<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's own purchasing (Phase 6): purchase orders the shop raises to a
 * vendor for its branch's stock (or for one job), and the goods receipts that
 * take the goods in. (Phase 4's `purchase_orders` stay what they were: a
 * customer account's spare parts.)
 *
 * Invariants the database holds:
 *  - a shop PO's reference comes from the organization's `shop_purchase_order`
 *    series and is unique per organization; what is STORED of its status is
 *    only draft / issued / cancelled, while "partially received" and
 *    "received" follow from the receipts;
 *  - once issued (R7) an order's lines, vendor, branch and total never change,
 *    only its status moves; orders are never deleted;
 *  - a line's total is exactly quantity × unit cost; a line buys a stocked
 *    item, or is bought for a job (and names the work-order line it serves);
 *  - a goods receipt is an issued stock document: its lines are append-only,
 *    and the receipt itself can only be voided once, never edited or deleted;
 *  - a receipt belongs to the order's own branch and location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_purchase_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('vendor_id')->index();
            $table->string('vendor_name');
            $table->string('reference', 32);
            $table->string('status', 16)->default('draft');
            $table->date('created_on');
            $table->date('expected_on')->nullable();
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->text('notes')->default('');
            $table->cents('total_cents')->default(0);
            $table->timestampTz('issued_at')->nullable();
            $table->string('issued_by_name')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'branch_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['vendor_id', 'organization_id'])->references(['id', 'organization_id'])->on('vendors')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("alter table shop_purchase_orders add constraint shop_purchase_orders_status_check check (status in ('draft', 'issued', 'cancelled'))");
        DB::statement(<<<'SQL'
            alter table shop_purchase_orders add constraint shop_purchase_orders_stamps_check check (
                (status <> 'draft' or issued_at is null)
                and (status <> 'issued' or issued_at is not null)
                and ((status = 'cancelled') = (cancelled_at is not null))
                and total_cents >= 0
            )
            SQL);

        Schema::create('shop_purchase_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('shop_purchase_order_id')->index();
            $table->unsignedSmallInteger('position')->default(0);
            // Null only for a purchase made for one job (it is never stocked).
            $table->ulid('item_id')->nullable()->index();
            $table->string('description');
            // In the PURCHASE unit (a case of 24), costed per purchase unit.
            $table->quantity('quantity');
            $table->cents('unit_cost_cents');
            $table->cents('line_total_cents');
            // The job's line this purchase is for (parts_source = purchased_for_job).
            $table->ulid('work_order_line_id')->nullable()->index();
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['shop_purchase_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('shop_purchase_orders')->restrictOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
            $table->foreign(['work_order_line_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_order_lines')->restrictOnDelete();
        });
        DB::statement('alter table shop_purchase_order_lines add constraint shop_purchase_order_lines_values_check check (quantity > 0 and unit_cost_cents >= 0 and line_total_cents = round(quantity * unit_cost_cents) and (item_id is not null or work_order_line_id is not null))');

        Schema::create('shop_purchase_order_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('shop_purchase_order_id')->index();
            $table->string('status', 16);
            $table->timestampTz('at');
            $table->ulid('actor_id')->nullable();
            $table->string('actor_name');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['shop_purchase_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('shop_purchase_orders')->restrictOnDelete();
        });
        AppendOnly::protect('shop_purchase_order_events');

        // R7: an issued order is immutable but for its status moving on.
        DB::unprepared(<<<'SQL'
            create or replace function shop_purchase_orders_keep_issued() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'purchase orders are never deleted; cancel instead' using errcode = 'restrict_violation';
                end if;
                if old.status <> 'draft' and (
                    new.branch_id is distinct from old.branch_id
                    or new.vendor_id is distinct from old.vendor_id
                    or new.vendor_name is distinct from old.vendor_name
                    or new.reference is distinct from old.reference
                    or new.total_cents is distinct from old.total_cents
                    or new.created_on is distinct from old.created_on
                ) then
                    raise exception 'purchase order % has been issued; only its status may change', old.reference
                        using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger shop_purchase_orders_keep_issued
                before update or delete on shop_purchase_orders
                for each row execute function shop_purchase_orders_keep_issued();

            create or replace function shop_purchase_order_lines_keep_issued() returns trigger language plpgsql as $$
            declare
                parent_status text;
                parent_id char(26);
            begin
                parent_id := case when tg_op = 'INSERT' then new.shop_purchase_order_id else old.shop_purchase_order_id end;
                select status into parent_status from shop_purchase_orders where id = parent_id;
                if parent_status is not null and parent_status <> 'draft' then
                    raise exception 'an issued purchase order''s lines never change' using errcode = 'restrict_violation';
                end if;
                return case when tg_op = 'DELETE' then old else new end;
            end
            $$;
            create trigger shop_purchase_order_lines_keep_issued
                before insert or update or delete on shop_purchase_order_lines
                for each row execute function shop_purchase_order_lines_keep_issued();
            SQL);

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('location_id');
            $table->ulid('shop_purchase_order_id')->index();
            $table->string('reference', 32);
            $table->string('status', 8)->default('posted');
            // A business date: Asia/Manila (R9).
            $table->date('received_on');
            $table->string('supplier_ref', 64)->nullable();
            $table->text('notes')->default('');
            $table->cents('total_cents')->default(0);
            $table->ulid('received_by')->nullable();
            $table->string('received_by_name');
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['id', 'organization_id']);
            $table->foreign(['shop_purchase_order_id', 'branch_id'])->references(['id', 'branch_id'])->on('shop_purchase_orders')->restrictOnDelete();
            $table->foreign(['shop_purchase_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('shop_purchase_orders')->restrictOnDelete();
            $table->foreign(['location_id', 'branch_id'])->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['received_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("alter table goods_receipts add constraint goods_receipts_status_check check (status in ('posted', 'voided') and ((status = 'voided') = (voided_at is not null)) and total_cents >= 0)");

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('goods_receipt_id')->index();
            $table->ulid('shop_purchase_order_line_id')->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->ulid('item_id')->nullable();
            // In the purchase unit, as ordered.
            $table->quantity('quantity');
            $table->cents('unit_cost_cents');
            $table->cents('line_total_cents');
            // What went on the shelf: stock units, at a stock unit's cost.
            $table->quantity('stock_quantity');
            $table->cents('stock_unit_cost_cents');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['goods_receipt_id', 'organization_id'])->references(['id', 'organization_id'])->on('goods_receipts')->restrictOnDelete();
            $table->foreign(['shop_purchase_order_line_id', 'organization_id'])->references(['id', 'organization_id'])->on('shop_purchase_order_lines')->restrictOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
        });
        DB::statement('alter table goods_receipt_lines add constraint goods_receipt_lines_values_check check (quantity > 0 and stock_quantity > 0 and unit_cost_cents >= 0 and stock_unit_cost_cents >= 0 and line_total_cents = round(quantity * unit_cost_cents))');
        AppendOnly::protect('goods_receipt_lines');

        // R7: a posted receipt can only be voided, once; never edited, never deleted.
        DB::unprepared(<<<'SQL'
            create or replace function goods_receipts_keep_posted() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'goods receipts are never deleted; void instead' using errcode = 'restrict_violation';
                end if;
                if old.status = 'voided' or (
                    new.branch_id is distinct from old.branch_id
                    or new.location_id is distinct from old.location_id
                    or new.shop_purchase_order_id is distinct from old.shop_purchase_order_id
                    or new.reference is distinct from old.reference
                    or new.received_on is distinct from old.received_on
                    or new.supplier_ref is distinct from old.supplier_ref
                    or new.total_cents is distinct from old.total_cents
                    or new.received_by_name is distinct from old.received_by_name
                ) then
                    raise exception 'goods receipt % has been posted; it can only be voided, once', old.reference
                        using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger goods_receipts_keep_posted
                before update or delete on goods_receipts
                for each row execute function goods_receipts_keep_posted();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists goods_receipts_keep_posted on goods_receipts; drop function if exists goods_receipts_keep_posted();');
        AppendOnly::unprotect('goods_receipt_lines');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        DB::unprepared('drop trigger if exists shop_purchase_order_lines_keep_issued on shop_purchase_order_lines; drop function if exists shop_purchase_order_lines_keep_issued();');
        DB::unprepared('drop trigger if exists shop_purchase_orders_keep_issued on shop_purchase_orders; drop function if exists shop_purchase_orders_keep_issued();');
        AppendOnly::unprotect('shop_purchase_order_events');
        Schema::dropIfExists('shop_purchase_order_events');
        Schema::dropIfExists('shop_purchase_order_lines');
        Schema::dropIfExists('shop_purchase_orders');
    }
};
