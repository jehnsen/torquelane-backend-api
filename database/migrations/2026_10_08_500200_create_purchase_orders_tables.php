<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer account's purchase orders for its own spare parts, their lines,
 * the due items each line covers (task × vehicle, so the forecast stops
 * counting them), and an append-only status history.
 *
 * Invariants the database holds:
 *  - `reference` is issued at creation from the organization's
 *    `purchase_order` series and unique per organization;
 *  - a line's total is exactly quantity × unit cost (CHECK), and the order's
 *    total is stamped from its lines in the same transaction;
 *  - a line's part is the same account's part (composite FK);
 *  - once an order has left draft (R7) its lines, vendor, total and account
 *    never change (triggers); only its status moves on;
 *  - the status history is append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->index();
            $table->string('reference', 32);
            $table->string('vendor');
            $table->string('status', 16)->default('draft');
            $table->date('created_on');
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->text('notes')->default('');
            $table->cents('total_cents')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->string('sent_by_name')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->string('received_by_name')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'customer_account_id']);
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("alter table purchase_orders add constraint purchase_orders_status_check check (status in ('draft', 'sent', 'received', 'cancelled'))");
        DB::statement(<<<'SQL'
            alter table purchase_orders add constraint purchase_orders_stamps_check check (
                (status not in ('sent', 'received') or sent_at is not null)
                and (status <> 'draft' or sent_at is null)
                and ((status = 'received') = (received_at is not null))
                and ((status = 'cancelled') = (cancelled_at is not null))
            )
            SQL);

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('purchase_order_id')->index();
            $table->ulid('customer_account_id');
            // Nullable: a line can name an item off the catalogue; the description is the label.
            $table->ulid('fleet_part_id')->nullable()->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('description');
            $table->integer('quantity');
            $table->cents('unit_cost_cents');
            $table->cents('line_total_cents');
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['purchase_order_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['fleet_part_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('fleet_parts')->restrictOnDelete();
            $table->foreign(['purchase_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('purchase_orders')->restrictOnDelete();
        });
        DB::statement('alter table purchase_order_lines add constraint purchase_order_lines_total_check check (quantity > 0 and unit_cost_cents >= 0 and line_total_cents = quantity * unit_cost_cents)');

        Schema::create('purchase_order_line_tasks', function (Blueprint $table) {
            $table->ulid('purchase_order_line_id');
            $table->ulid('service_task_id')->index();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();

            $table->primary(['purchase_order_line_id', 'service_task_id']);
            $table->foreign(['purchase_order_line_id', 'organization_id'])->references(['id', 'organization_id'])->on('purchase_order_lines')->cascadeOnDelete();
            $table->foreign(['service_task_id', 'organization_id'])->references(['id', 'organization_id'])->on('service_tasks')->restrictOnDelete();
        });

        Schema::create('purchase_order_line_vehicles', function (Blueprint $table) {
            $table->ulid('purchase_order_line_id');
            $table->ulid('vehicle_id')->index();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();

            $table->primary(['purchase_order_line_id', 'vehicle_id']);
            $table->foreign(['purchase_order_line_id', 'organization_id'])->references(['id', 'organization_id'])->on('purchase_order_lines')->cascadeOnDelete();
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
        });

        Schema::create('purchase_order_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('purchase_order_id')->index();
            $table->string('status', 16);
            $table->timestampTz('at');
            $table->ulid('actor_id')->nullable();
            $table->string('actor_name');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['purchase_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('purchase_orders')->restrictOnDelete();
        });
        AppendOnly::protect('purchase_order_events');

        // R7: an issued purchase order is immutable but for its status moving on.
        DB::unprepared(<<<'SQL'
            create or replace function purchase_orders_keep_issued() returns trigger language plpgsql as $$
            begin
                if old.status <> 'draft' and (
                    new.customer_account_id is distinct from old.customer_account_id
                    or new.reference is distinct from old.reference
                    or new.vendor is distinct from old.vendor
                    or new.total_cents is distinct from old.total_cents
                    or new.created_on is distinct from old.created_on
                ) then
                    raise exception 'purchase order % has been issued; only its status may change', old.reference
                        using errcode = 'restrict_violation';
                end if;
                if tg_op = 'DELETE' then
                    raise exception 'purchase orders are never deleted; cancel instead'
                        using errcode = 'restrict_violation';
                end if;
                return new;
            end; $$;
            create trigger purchase_orders_keep_issued before update or delete on purchase_orders
                for each row execute function purchase_orders_keep_issued();

            create or replace function purchase_order_lines_keep_issued() returns trigger language plpgsql as $$
            declare
                order_status text;
            begin
                select status into order_status from purchase_orders where id = old.purchase_order_id;
                if order_status is distinct from 'draft' then
                    raise exception 'the lines of an issued purchase order never change'
                        using errcode = 'restrict_violation';
                end if;
                if tg_op = 'DELETE' then
                    return old;
                end if;
                return new;
            end; $$;
            create trigger purchase_order_lines_keep_issued before update or delete on purchase_order_lines
                for each row execute function purchase_order_lines_keep_issued();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists purchase_order_lines_keep_issued on purchase_order_lines;
            drop function if exists purchase_order_lines_keep_issued();
            drop trigger if exists purchase_orders_keep_issued on purchase_orders;
            drop function if exists purchase_orders_keep_issued();
            SQL);
        AppendOnly::unprotect('purchase_order_events');
        Schema::dropIfExists('purchase_order_events');
        Schema::dropIfExists('purchase_order_line_vehicles');
        Schema::dropIfExists('purchase_order_line_tasks');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
