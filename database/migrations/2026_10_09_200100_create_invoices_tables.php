<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (Phase 7): order-to-cash.
 *
 * Invariants the database holds:
 *  - a draft is unnumbered; anything issued carries its number from the
 *    `invoice` series for good, a void one included (R8);
 *  - once issued (R7) nothing on the invoice changes but its status, what has
 *    been paid against it, and the void stamp; its lines never change; a
 *    void invoice changes no more; only a draft may be deleted;
 *  - the totals add up: total due = VATable sales + VAT + VAT-exempt +
 *    zero-rated + non-VAT sales, and what is paid never exceeds it; the
 *    stored status agrees with what is paid;
 *  - a line's total is exactly round(quantity × unit price) less its discount;
 *  - a work order is on at most ONE standing invoice (partial unique index on
 *    the link); voiding the invoice releases the link, so the order can be
 *    invoiced again. A link is to an order of the invoice's own account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('customer_account_id')->index();
            $table->string('number', 32)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('source', 16);
            // Business dates, Asia/Manila (R9). Set at issue.
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);

            // Who the invoice is to, and from: snapshots, refreshed at issue and frozen after.
            $table->string('buyer_name');
            $table->string('buyer_tin', 11)->nullable();
            $table->text('buyer_address')->nullable();
            $table->string('seller_name');
            $table->string('seller_business_style')->nullable();
            $table->string('seller_tin', 11)->nullable();
            $table->string('seller_branch_code', 5)->nullable();
            $table->text('seller_address')->nullable();
            $table->boolean('seller_vat_registered');
            $table->text('seller_header')->nullable();
            $table->text('seller_footer')->nullable();
            $table->boolean('prices_include_vat');
            $table->rate('vat_rate_pct');

            $table->cents('vatable_sales_cents')->default(0);
            $table->cents('vat_exempt_sales_cents')->default(0);
            $table->cents('zero_rated_sales_cents')->default(0);
            $table->cents('non_vat_sales_cents')->default(0);
            $table->cents('discount_total_cents')->default(0);
            $table->cents('vat_amount_cents')->default(0);
            $table->cents('total_due_cents')->default(0);
            $table->cents('paid_cents')->default(0);

            $table->text('notes')->default('');
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampTz('issued_at')->nullable();
            $table->string('issued_by_name')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'number']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'customer_account_id']);
            $table->index(['organization_id', 'status']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("alter table invoices add constraint invoices_status_check check (status in ('draft', 'issued', 'partially_paid', 'paid', 'void') and source in ('work_orders', 'manual'))");
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_lifecycle_check check (
                ((status = 'draft') = (number is null))
                and (status = 'draft' or (issue_date is not null and due_date is not null and issued_at is not null and due_date >= issue_date))
                and ((status = 'void') = (voided_at is not null))
            )
            SQL);
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_money_check check (
                vatable_sales_cents >= 0 and vat_exempt_sales_cents >= 0 and zero_rated_sales_cents >= 0
                and non_vat_sales_cents >= 0 and discount_total_cents >= 0 and vat_amount_cents >= 0
                and total_due_cents = vatable_sales_cents + vat_amount_cents + vat_exempt_sales_cents + zero_rated_sales_cents + non_vat_sales_cents
                and paid_cents between 0 and total_due_cents
                and (seller_vat_registered or (vat_amount_cents = 0 and vatable_sales_cents = 0))
            )
            SQL);
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_paid_status_check check (
                (status not in ('draft', 'issued', 'void') or paid_cents = 0)
                and (status <> 'partially_paid' or (paid_cents > 0 and paid_cents < total_due_cents))
                and (status <> 'paid' or paid_cents = total_due_cents)
            )
            SQL);

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('invoice_id')->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('kind', 16);
            $table->string('description', 500);
            $table->ulid('work_order_id')->nullable()->index();
            $table->ulid('work_order_line_id')->nullable()->index();
            $table->ulid('item_id')->nullable();
            $table->ulid('service_task_id')->nullable();
            $table->quantity('quantity');
            $table->cents('unit_price_cents');
            $table->cents('discount_cents')->default(0);
            $table->string('tax_class', 16);
            $table->cents('line_total_cents');
            $table->timestampsTz();

            $table->foreign(['invoice_id', 'organization_id'])->references(['id', 'organization_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['work_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_orders')->restrictOnDelete();
            $table->foreign(['work_order_line_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_order_lines')->restrictOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
            $table->foreign(['service_task_id', 'organization_id'])->references(['id', 'organization_id'])->on('service_tasks')->restrictOnDelete();
        });
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

        Schema::create('invoice_work_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('invoice_id')->index();
            $table->ulid('customer_account_id');
            $table->ulid('work_order_id')->index();
            // Set when the invoice is voided: the order may be invoiced again.
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['invoice_id', 'work_order_id']);
            $table->foreign(['invoice_id', 'organization_id'])->references(['id', 'organization_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['invoice_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['work_order_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('work_orders')->restrictOnDelete();
        });
        // A work order is invoiced once: at most one standing link.
        DB::statement('create unique index invoice_work_orders_standing_unique on invoice_work_orders (work_order_id) where released_at is null');

        DB::unprepared(<<<'SQL'
            create or replace function invoices_keep_issued() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    if old.status <> 'draft' then
                        raise exception 'invoice % has been issued; void it instead', old.number using errcode = 'restrict_violation';
                    end if;
                    return old;
                end if;
                if old.status = 'void' then
                    raise exception 'invoice % is void; it never changes again', old.number using errcode = 'restrict_violation';
                end if;
                if old.status <> 'draft' and (
                    new.branch_id is distinct from old.branch_id
                    or new.customer_account_id is distinct from old.customer_account_id
                    or new.number is distinct from old.number
                    or new.source is distinct from old.source
                    or new.issue_date is distinct from old.issue_date
                    or new.due_date is distinct from old.due_date
                    or new.payment_terms_days is distinct from old.payment_terms_days
                    or new.buyer_name is distinct from old.buyer_name
                    or new.buyer_tin is distinct from old.buyer_tin
                    or new.buyer_address is distinct from old.buyer_address
                    or new.seller_name is distinct from old.seller_name
                    or new.seller_business_style is distinct from old.seller_business_style
                    or new.seller_tin is distinct from old.seller_tin
                    or new.seller_branch_code is distinct from old.seller_branch_code
                    or new.seller_address is distinct from old.seller_address
                    or new.seller_vat_registered is distinct from old.seller_vat_registered
                    or new.seller_header is distinct from old.seller_header
                    or new.seller_footer is distinct from old.seller_footer
                    or new.prices_include_vat is distinct from old.prices_include_vat
                    or new.vat_rate_pct is distinct from old.vat_rate_pct
                    or new.vatable_sales_cents is distinct from old.vatable_sales_cents
                    or new.vat_exempt_sales_cents is distinct from old.vat_exempt_sales_cents
                    or new.zero_rated_sales_cents is distinct from old.zero_rated_sales_cents
                    or new.non_vat_sales_cents is distinct from old.non_vat_sales_cents
                    or new.discount_total_cents is distinct from old.discount_total_cents
                    or new.vat_amount_cents is distinct from old.vat_amount_cents
                    or new.total_due_cents is distinct from old.total_due_cents
                    or new.notes is distinct from old.notes
                    or new.issued_at is distinct from old.issued_at
                    or new.issued_by_name is distinct from old.issued_by_name
                ) then
                    raise exception 'invoice % has been issued; only its payment status may change', old.number
                        using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger invoices_keep_issued
                before update or delete on invoices
                for each row execute function invoices_keep_issued();

            create or replace function invoice_children_keep_issued() returns trigger language plpgsql as $$
            declare
                parent_status text;
            begin
                select status into parent_status from invoices
                    where id = case when tg_op = 'INSERT' then new.invoice_id else old.invoice_id end;
                if parent_status is not null and parent_status <> 'draft' then
                    -- The one change an issued invoice's link may take: released, once, by the void.
                    -- (Nested: a line row has no released_at, and plpgsql does not short-circuit.)
                    if tg_table_name = 'invoice_work_orders' and tg_op = 'UPDATE' then
                        if old.released_at is null and new.released_at is not null
                            and new.invoice_id = old.invoice_id and new.work_order_id = old.work_order_id
                            and new.customer_account_id = old.customer_account_id then
                            return new;
                        end if;
                    end if;
                    raise exception 'an issued invoice''s lines and orders never change' using errcode = 'restrict_violation';
                end if;
                return case when tg_op = 'DELETE' then old else new end;
            end
            $$;
            create trigger invoice_lines_keep_issued
                before insert or update or delete on invoice_lines
                for each row execute function invoice_children_keep_issued();
            create trigger invoice_work_orders_keep_issued
                before insert or update or delete on invoice_work_orders
                for each row execute function invoice_children_keep_issued();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists invoice_work_orders_keep_issued on invoice_work_orders;
            drop trigger if exists invoice_lines_keep_issued on invoice_lines;
            drop function if exists invoice_children_keep_issued();
            drop trigger if exists invoices_keep_issued on invoices;
            drop function if exists invoices_keep_issued();
            SQL);
        Schema::dropIfExists('invoice_work_orders');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
