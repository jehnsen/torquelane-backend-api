<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work orders and what hangs off them: lines (the priced, per-line-approved
 * quote), the service tasks they discharge, and the parts fitted at close.
 *
 * Invariants the database holds, not just the actions:
 *  - `reference` is '' until draft → pending_approval and unique per
 *    organization once issued (partial index); only a draft may lack one.
 *  - a line's part/labour cost is exactly round(qty × rate) (CHECK): the
 *    stored cost cannot drift from its inputs, and only recalc writes it.
 *  - an APPROVED line's quantities, rates and costs never change (trigger):
 *    the approved amount is the historical price the customer authorised.
 *  - a bay belongs to the order's own branch (composite FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            // The branch that took the order in; null for a portal request not yet taken in.
            $table->ulid('branch_id')->nullable()->index();
            // The branch carrying the work out, stamped on approval. Not `vendor`.
            $table->ulid('assigned_branch_id')->nullable();
            // Stamped at creation: the order stays with the account that owned the vehicle then.
            $table->ulid('customer_account_id')->index();
            $table->ulid('vehicle_id')->index();
            $table->string('reference', 32)->default('');
            $table->string('title');
            $table->string('type', 16);
            $table->string('status', 24)->default('draft');
            $table->string('priority', 16)->default('medium');
            $table->date('opened_on');
            $table->date('scheduled_for')->nullable();
            $table->string('scheduled_time', 5)->nullable();
            $table->ulid('bay_id')->nullable();
            $table->ulid('technician_id')->nullable();
            // The technician's name when assigned: the label survives a renamed or removed record.
            $table->string('technician_name')->nullable();
            // A genuine third-party subcontractor only; '' for in-house work.
            $table->string('vendor')->default('');
            $table->quantity('odometer_at_intake')->nullable();
            $table->quantity('odometer_at_service')->nullable();
            // The estimate captured from the lines; itemised lines and parts win once recorded.
            $table->cents('labor_cost_cents')->default(0);
            $table->cents('parts_cost_cents')->default(0);
            $table->text('findings')->default('');
            $table->text('notes')->default('');
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('pending_approval_entered_at')->nullable();
            $table->rate('approval_wait_hours')->nullable();
            $table->date('completed_on')->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->ulid('collected_by')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'scheduled_for']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['assigned_branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['bay_id', 'branch_id'])->references(['id', 'branch_id'])->on('bays')->restrictOnDelete();
            $table->foreign('technician_id')->references('id')->on('technicians')->restrictOnDelete();
            $table->foreign(['collected_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });

        DB::statement("create unique index work_orders_reference_unique on work_orders (organization_id, reference) where reference <> ''");
        DB::statement("alter table work_orders add constraint work_orders_status_check check (status in ('draft', 'pending_approval', 'approved', 'partially_approved', 'scheduled', 'in_progress', 'closed', 'declined', 'cancelled'))");
        DB::statement("alter table work_orders add constraint work_orders_type_check check (type in ('preventive', 'corrective', 'inspection'))");
        DB::statement("alter table work_orders add constraint work_orders_priority_check check (priority in ('low', 'medium', 'high', 'critical'))");
        // Numbered on leaving draft; a declined order reopened as a draft keeps its number.
        DB::statement("alter table work_orders add constraint work_orders_reference_check check (status = 'draft' or reference <> '')");
        DB::statement("alter table work_orders add constraint work_orders_time_check check (scheduled_time is null or scheduled_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')");
        // A bay is a branch's: a bay without a branch would slip past the composite FK.
        DB::statement('alter table work_orders add constraint work_orders_bay_branch_check check (bay_id is null or branch_id is not null)');
        DB::statement('alter table work_orders add constraint work_orders_money_check check (labor_cost_cents >= 0 and parts_cost_cents >= 0)');
        DB::statement("alter table work_orders add constraint work_orders_collected_check check ((collected_at is null) = (collected_by is null) and (collected_at is null or status = 'closed'))");

        Schema::create('work_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('work_order_id')->index();
            $table->unsignedSmallInteger('position')->default(0);
            // Nullable by design: an unlisted repair has no catalogue task. The
            // description survives as the label if the task is later removed.
            $table->ulid('service_task_id')->nullable();
            $table->text('description');
            $table->string('category', 16)->default('other');
            $table->quantity('quantity')->default(1);
            $table->cents('unit_part_rate_cents')->default(0);
            $table->cents('part_cost_cents')->default(0);
            $table->quantity('labour_hours')->default(0);
            $table->cents('labour_rate_cents')->default(0);
            $table->cents('labour_cost_cents')->default(0);
            $table->string('urgency', 16)->default('recommended');
            $table->string('parts_source', 24)->default('supplier_provided');
            $table->string('approval_status', 16)->default('pending');
            $table->ulid('approved_by')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->jsonb('photos')->default('[]');
            $table->timestampsTz();

            $table->foreign(['work_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_orders')->restrictOnDelete();
            $table->foreign(['service_task_id', 'organization_id'])->references(['id', 'organization_id'])->on('service_tasks')->restrictOnDelete();
            $table->foreign(['approved_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });

        DB::statement("alter table work_order_lines add constraint work_order_lines_category_check check (category in ('engine', 'drivetrain', 'brakes', 'tires', 'electrical', 'safety', 'body', 'other'))");
        DB::statement("alter table work_order_lines add constraint work_order_lines_urgency_check check (urgency in ('safety_critical', 'recommended', 'optional'))");
        DB::statement("alter table work_order_lines add constraint work_order_lines_source_check check (parts_source in ('own_stock', 'supplier_provided'))");
        DB::statement("alter table work_order_lines add constraint work_order_lines_status_check check (approval_status in ('pending', 'approved', 'declined', 'deferred'))");
        DB::statement('alter table work_order_lines add constraint work_order_lines_inputs_check check (quantity >= 0 and unit_part_rate_cents >= 0 and labour_hours >= 0 and labour_rate_cents >= 0)');
        // recalcLine, enforced: Postgres round(numeric) is half away from zero, like Billing::roundCents.
        DB::statement('alter table work_order_lines add constraint work_order_lines_cost_check check (part_cost_cents = round(quantity * unit_part_rate_cents) and labour_cost_cents = round(labour_hours * labour_rate_cents))');
        DB::statement("alter table work_order_lines add constraint work_order_lines_photos_check check (jsonb_typeof(photos) = 'array')");
        DB::unprepared(<<<'SQL'
            create or replace function work_order_lines_keep_approved_price() returns trigger
            language plpgsql as $$
            begin
                if old.approval_status = 'approved' and (
                    new.quantity is distinct from old.quantity
                    or new.unit_part_rate_cents is distinct from old.unit_part_rate_cents
                    or new.part_cost_cents is distinct from old.part_cost_cents
                    or new.labour_hours is distinct from old.labour_hours
                    or new.labour_rate_cents is distinct from old.labour_rate_cents
                    or new.labour_cost_cents is distinct from old.labour_cost_cents
                ) then
                    raise exception 'An approved line is the price the customer authorised; it cannot be re-priced.'
                        using errcode = 'P0001';
                end if;
                if tg_op = 'DELETE' and old.approval_status = 'approved' then
                    raise exception 'An approved line cannot be deleted.' using errcode = 'P0001';
                end if;
                return case when tg_op = 'DELETE' then old else new end;
            end;
            $$;
            create trigger work_order_lines_keep_approved_price
                before update or delete on work_order_lines
                for each row execute function work_order_lines_keep_approved_price();
            SQL);

        Schema::create('work_order_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('work_order_id');
            $table->ulid('service_task_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['work_order_id', 'service_task_id']);
            $table->foreign(['work_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_orders')->restrictOnDelete();
            $table->foreign(['service_task_id', 'organization_id'])->references(['id', 'organization_id'])->on('service_tasks')->restrictOnDelete();
        });

        Schema::create('work_order_parts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('work_order_id')->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('part_number', 64)->nullable();
            $table->string('name');
            $table->quantity('quantity');
            $table->cents('unit_cost_cents');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['work_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_orders')->restrictOnDelete();
        });

        DB::statement('alter table work_order_parts add constraint work_order_parts_values_check check (quantity > 0 and unit_cost_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_parts');
        Schema::dropIfExists('work_order_tasks');
        DB::unprepared('drop trigger if exists work_order_lines_keep_approved_price on work_order_lines; drop function if exists work_order_lines_keep_approved_price();');
        Schema::dropIfExists('work_order_lines');
        Schema::dropIfExists('work_orders');
    }
};
