<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 on the repair core, additively (R1, R10):
 *
 *  - a work-order line's `parts_source` gains three values beside the two it
 *    always had. `own_stock` and `supplier_provided` keep their meaning (the
 *    part is charged; no stock moves), so no existing line changes. The new
 *    ones: `customer_supplied` (no stock move, NO part charge: the database
 *    holds the part cost at zero), `shop_stock` (issued from branch
 *    inventory: names an item, and only a shop-stock line does), and
 *    `purchased_for_job` (bought on a purchase order for this job);
 *  - the line may name the `item` it issues;
 *  - an approved line's source and item freeze with its price;
 *  - an account's / branch's default source may be any of the four that do
 *    not need an item;
 *  - the document series gain the shop's purchase orders (their own `SPO`
 *    series, so Phase 4's customer-account `PO` numbers carry on undisturbed),
 *    stock transfers and stock counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_lines', function (Blueprint $table) {
            $table->ulid('item_id')->nullable()->index();
        });
        DB::statement('alter table work_order_lines add constraint work_order_lines_item_fk foreign key (item_id, organization_id) references items (id, organization_id) on delete restrict');
        DB::statement('alter table work_order_lines drop constraint work_order_lines_source_check');
        DB::statement("alter table work_order_lines add constraint work_order_lines_source_check check (parts_source in ('own_stock', 'supplier_provided', 'customer_supplied', 'shop_stock', 'purchased_for_job'))");
        DB::statement("alter table work_order_lines add constraint work_order_lines_shop_stock_item_check check ((parts_source = 'shop_stock') = (item_id is not null))");
        DB::statement("alter table work_order_lines add constraint work_order_lines_customer_supplied_check check (parts_source <> 'customer_supplied' or (unit_part_rate_cents = 0 and part_cost_cents = 0))");

        DB::unprepared(<<<'SQL'
            create or replace function work_order_lines_keep_approved_source() returns trigger
            language plpgsql as $$
            begin
                if old.approval_status = 'approved' and (
                    new.parts_source is distinct from old.parts_source
                    or new.item_id is distinct from old.item_id
                ) then
                    raise exception 'An approved line keeps the source its parts were approved under.'
                        using errcode = 'P0001';
                end if;
                return new;
            end;
            $$;
            create trigger work_order_lines_keep_approved_source
                before update on work_order_lines
                for each row execute function work_order_lines_keep_approved_source();
            SQL);

        DB::statement('alter table approval_settings drop constraint approval_settings_values_check');
        DB::statement(<<<'SQL'
            alter table approval_settings add constraint approval_settings_values_check check (
                coalesce(auto_approve_under_cents, 0) >= 0 and coalesce(ops_approval_under_cents, 0) >= 0
                and coalesce(variance_threshold_pct, 0) >= 0 and coalesce(monthly_budget_cents, 0) >= 0
                and coalesce(vat_rate_pct, 0) between 0 and 100 and coalesce(misc_fee_flat_cents, 0) >= 0
                and coalesce(default_labour_rate_cents, 0) >= 0
                and (default_parts_source is null or default_parts_source in ('own_stock', 'supplier_provided', 'customer_supplied', 'purchased_for_job'))
            )
            SQL);

        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count'))");
    }

    public function down(): void
    {
        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry'))");

        DB::statement('alter table approval_settings drop constraint approval_settings_values_check');
        DB::statement(<<<'SQL'
            alter table approval_settings add constraint approval_settings_values_check check (
                coalesce(auto_approve_under_cents, 0) >= 0 and coalesce(ops_approval_under_cents, 0) >= 0
                and coalesce(variance_threshold_pct, 0) >= 0 and coalesce(monthly_budget_cents, 0) >= 0
                and coalesce(vat_rate_pct, 0) between 0 and 100 and coalesce(misc_fee_flat_cents, 0) >= 0
                and coalesce(default_labour_rate_cents, 0) >= 0
                and (default_parts_source is null or default_parts_source in ('own_stock', 'supplier_provided'))
            )
            SQL);

        DB::unprepared('drop trigger if exists work_order_lines_keep_approved_source on work_order_lines; drop function if exists work_order_lines_keep_approved_source();');
        DB::statement('alter table work_order_lines drop constraint if exists work_order_lines_customer_supplied_check');
        DB::statement('alter table work_order_lines drop constraint if exists work_order_lines_shop_stock_item_check');
        DB::statement('alter table work_order_lines drop constraint work_order_lines_source_check');
        DB::statement("alter table work_order_lines add constraint work_order_lines_source_check check (parts_source in ('own_stock', 'supplier_provided'))");
        DB::statement('alter table work_order_lines drop constraint if exists work_order_lines_item_fk');
        Schema::table('work_order_lines', function (Blueprint $table) {
            $table->dropColumn('item_id');
        });
    }
};
