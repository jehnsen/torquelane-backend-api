<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 on the earlier tables, additively (R1, R10):
 *
 *  - a branch carries what its invoices print at the top and the bottom: the
 *    BIR-registered name and business style, and free header / footer text
 *    (permit and series details, as the accountant words them);
 *  - a work order gets `released_at` / `released_by`: the vehicle handed back
 *    at the counter. Until now that act stamped `collected_at`, which also
 *    meant "revenue recognised". From Phase 7 `collected_at` is set when the
 *    order's invoice is PAID (a compatibility field the shop reports keep
 *    reading); handing the vehicle back is its own record. Every order
 *    collected so far was handed back at that moment, so it is backfilled;
 *  - the document series gain `payment` (acknowledgment receipts, `PAY-`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('registered_name')->nullable();
            $table->string('business_style')->nullable();
            $table->text('invoice_header')->nullable();
            $table->text('invoice_footer')->nullable();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestampTz('released_at')->nullable();
            $table->ulid('released_by')->nullable();
        });
        DB::statement('alter table work_orders add constraint work_orders_released_by_fk foreign key (released_by, organization_id) references users (id, organization_id) on delete restrict');
        DB::statement('update work_orders set released_at = collected_at, released_by = collected_by where collected_at is not null');
        DB::statement("alter table work_orders add constraint work_orders_released_check check ((released_at is null) = (released_by is null) and (released_at is null or status = 'closed'))");

        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count', 'payment'))");
    }

    public function down(): void
    {
        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count'))");

        DB::statement('alter table work_orders drop constraint if exists work_orders_released_check');
        DB::statement('alter table work_orders drop constraint if exists work_orders_released_by_fk');
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['released_at', 'released_by']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['registered_name', 'business_style', 'invoice_header', 'invoice_footer']);
        });
    }
};
