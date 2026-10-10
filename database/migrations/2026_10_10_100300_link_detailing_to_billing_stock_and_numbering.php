<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detailing (Phase 9) meets the phases before it. Additive only (R1, R10):
 *
 *  - numbering: a `detailing_booking` series (BK-);
 *  - billing: an invoice may be raised from a completed booking (`source`
 *    `detailing`), its lines the package (`detailing`) and each add-on
 *    (`addon`); `invoice_bookings` holds the claim, as `invoice_work_orders`
 *    does for jobs: a booking is on at most ONE standing invoice, and voiding
 *    the invoice releases it;
 *  - stock: a package's materials leave the shelf as `consumption` moves whose
 *    source is the booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count', 'payment', 'detailing_booking'))");

        DB::statement('alter table stock_moves drop constraint stock_moves_source_check');
        DB::statement("alter table stock_moves add constraint stock_moves_source_check check (source_type in ('manual', 'goods_receipt', 'work_order_line', 'stock_count', 'stock_transfer', 'detailing_booking') and (source_type = 'manual' or source_id is not null))");

        DB::statement('alter table invoices drop constraint invoices_status_check');
        DB::statement("alter table invoices add constraint invoices_status_check check (status in ('draft', 'issued', 'partially_paid', 'paid', 'void') and source in ('work_orders', 'manual', 'detailing'))");

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->ulid('detailing_booking_id')->nullable()->index();
            $table->foreign(['detailing_booking_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_bookings')->restrictOnDelete();
        });
        DB::statement('alter table invoice_lines drop constraint invoice_lines_values_check');
        DB::statement(<<<'SQL'
            alter table invoice_lines add constraint invoice_lines_values_check check (
                kind in ('parts', 'labour', 'fee', 'manual', 'detailing', 'addon')
                and tax_class in ('vatable', 'vat_exempt', 'zero_rated')
                and quantity > 0 and unit_price_cents >= 0
                and discount_cents between 0 and round(quantity * unit_price_cents)
                and line_total_cents = round(quantity * unit_price_cents) - discount_cents
                and (work_order_line_id is null or work_order_id is not null)
                and ((kind in ('detailing', 'addon')) = (detailing_booking_id is not null))
            )
            SQL);

        Schema::create('invoice_bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('invoice_id')->index();
            $table->ulid('customer_account_id');
            $table->ulid('booking_id')->index();
            // Set when the invoice is voided: the booking may be invoiced again.
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['invoice_id', 'booking_id']);
            $table->foreign(['invoice_id', 'organization_id'])->references(['id', 'organization_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['invoice_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['booking_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('detailing_bookings')->restrictOnDelete();
        });
        // A booking is invoiced once: at most one standing link.
        DB::statement('create unique index invoice_bookings_standing_unique on invoice_bookings (booking_id) where released_at is null');

        // The same guard `invoice_work_orders` has: an issued invoice's links never change, but for the void's release.
        DB::unprepared(<<<'SQL'
            create or replace function invoice_bookings_keep_issued() returns trigger language plpgsql as $$
            declare
                parent_status text;
            begin
                select status into parent_status from invoices
                    where id = case when tg_op = 'INSERT' then new.invoice_id else old.invoice_id end;
                if parent_status is not null and parent_status <> 'draft' then
                    if tg_op = 'UPDATE' then
                        if old.released_at is null and new.released_at is not null
                            and new.invoice_id = old.invoice_id and new.booking_id = old.booking_id
                            and new.customer_account_id = old.customer_account_id then
                            return new;
                        end if;
                    end if;
                    raise exception 'an issued invoice''s lines and bookings never change' using errcode = 'restrict_violation';
                end if;
                return case when tg_op = 'DELETE' then old else new end;
            end
            $$;
            create trigger invoice_bookings_keep_issued
                before insert or update or delete on invoice_bookings
                for each row execute function invoice_bookings_keep_issued();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists invoice_bookings_keep_issued on invoice_bookings; drop function if exists invoice_bookings_keep_issued();');
        Schema::dropIfExists('invoice_bookings');

        DB::statement('alter table invoice_lines drop constraint invoice_lines_values_check');
        DB::statement(<<<'SQL'
            alter table invoice_lines add constraint invoice_lines_values_check check (
                kind in ('parts', 'labour', 'fee', 'manual')
                and tax_class in ('vatable', 'vat_exempt', 'zero_rated')
                and quantity > 0 and unit_price_cents >= 0
                and discount_cents between 0 and round(quantity * unit_price_cents)
                and line_total_cents = round(quantity * unit_price_cents) - discount_cents
                and (work_order_line_id is null or work_order_id is not null)
            )
            SQL);
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropForeign(['detailing_booking_id', 'organization_id']);
            $table->dropColumn('detailing_booking_id');
        });

        DB::statement('alter table invoices drop constraint invoices_status_check');
        DB::statement("alter table invoices add constraint invoices_status_check check (status in ('draft', 'issued', 'partially_paid', 'paid', 'void') and source in ('work_orders', 'manual'))");

        DB::statement('alter table stock_moves drop constraint stock_moves_source_check');
        DB::statement("alter table stock_moves add constraint stock_moves_source_check check (source_type in ('manual', 'goods_receipt', 'work_order_line', 'stock_count', 'stock_transfer') and (source_type = 'manual' or source_id is not null))");

        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count', 'payment'))");
    }
};
