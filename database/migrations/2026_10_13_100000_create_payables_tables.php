<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts payable (Phase 12): what vendors bill the shop and what the shop
 * pays them.
 *
 * Invariants the database holds:
 *  - a vendor bill is numbered from the `vendor_bill` series when recorded
 *    (R8) and is then a posted document (R7): only its status, what has been
 *    paid against it and the void stamp move; its lines are append-only; a
 *    void bill never changes again; nothing is ever deleted;
 *  - the totals add up: total = VATable purchases + input VAT + VAT-exempt +
 *    zero-rated + non-VAT purchases; payable = total less what was withheld;
 *    what is paid never exceeds the payable, and the status agrees with it;
 *  - a vendor's invoice number is recorded once among its standing bills;
 *  - a goods receipt line is billed on at most ONE standing bill, and only
 *    while its receipt stands (trigger, the receipt row locked);
 *  - a vendor payment is numbered from the `vendor_payment` series, never
 *    edited or deleted, voided once; its allocations are append-only, go to
 *    bills of the SAME vendor (composite keys) and never add up to more than
 *    the payment;
 *  - a bill's `paid_cents` is the allocations of its payments that still
 *    stand, checked at COMMIT (deferred constraint triggers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // What a bill, a 2307 and the summary list of purchases print.
            $table->string('registered_name')->nullable();
            $table->string('tin', 11)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_vat_registered')->default(true);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            // What the shop usually withholds from this vendor (BIR alphanumeric tax code + rate).
            $table->string('default_atc_code', 8)->nullable();
            $table->rate('default_withholding_rate_pct')->nullable();
        });
        DB::statement(<<<'SQL'
            alter table vendors add constraint vendors_tax_check check (
                (tin is null or tin ~ '^\d{3}-\d{3}-\d{3}$')
                and (default_withholding_rate_pct is null or (default_withholding_rate_pct >= 0 and default_withholding_rate_pct <= 100))
                and ((default_atc_code is null) = (default_withholding_rate_pct is null))
            )
            SQL);

        // A bill line names a receipt line of its own organization.
        DB::statement('alter table goods_receipt_lines add constraint goods_receipt_lines_id_organization_unique unique (id, organization_id)');

        Schema::create('vendor_bills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('vendor_id')->index();
            $table->string('number', 32);
            $table->string('status', 16)->default('posted');
            // The vendor's own invoice number, as printed on their document.
            $table->string('vendor_invoice_no', 64);
            // Business dates, Asia/Manila (R9).
            $table->date('bill_date');
            $table->date('due_date');

            // Who billed: a snapshot, so a 2307 and the purchases list read as the bill did.
            $table->string('vendor_name');
            $table->string('vendor_tin', 11)->nullable();
            $table->text('vendor_address')->nullable();
            $table->boolean('vendor_vat_registered');
            $table->boolean('prices_include_vat');
            $table->rate('vat_rate_pct');

            $table->cents('vatable_purchases_cents')->default(0);
            $table->cents('vat_exempt_purchases_cents')->default(0);
            $table->cents('zero_rated_purchases_cents')->default(0);
            $table->cents('non_vat_purchases_cents')->default(0);
            $table->cents('input_vat_cents')->default(0);
            $table->cents('total_cents')->default(0);

            // Expanded withholding tax taken off what is paid to the vendor.
            $table->string('atc_code', 8)->nullable();
            $table->rate('withholding_rate_pct')->nullable();
            $table->cents('withholding_base_cents')->default(0);
            $table->cents('withholding_cents')->default(0);
            $table->cents('payable_cents')->default(0);
            $table->cents('paid_cents')->default(0);

            $table->text('notes')->default('');
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'number']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'vendor_id']);
            $table->index(['organization_id', 'status']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['vendor_id', 'organization_id'])->references(['id', 'organization_id'])->on('vendors')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table vendor_bills add constraint vendor_bills_status_check check (
                status in ('posted', 'partially_paid', 'paid', 'void')
                and ((status = 'void') = (voided_at is not null))
                and vendor_invoice_no <> '' and due_date >= bill_date
            )
            SQL);
        DB::statement(<<<'SQL'
            alter table vendor_bills add constraint vendor_bills_money_check check (
                vatable_purchases_cents >= 0 and vat_exempt_purchases_cents >= 0 and zero_rated_purchases_cents >= 0
                and non_vat_purchases_cents >= 0 and input_vat_cents >= 0
                and total_cents = vatable_purchases_cents + input_vat_cents + vat_exempt_purchases_cents + zero_rated_purchases_cents + non_vat_purchases_cents
                and total_cents > 0
                and (vendor_vat_registered or (input_vat_cents = 0 and vatable_purchases_cents = 0))
                and withholding_cents >= 0 and withholding_base_cents >= 0
                and ((atc_code is null) = (withholding_rate_pct is null))
                and (atc_code is not null or withholding_cents = 0)
                and payable_cents = total_cents - withholding_cents and payable_cents >= 0
                and paid_cents between 0 and payable_cents
            )
            SQL);
        DB::statement(<<<'SQL'
            alter table vendor_bills add constraint vendor_bills_paid_status_check check (
                (status <> 'void' or paid_cents = 0)
                and (status <> 'posted' or paid_cents = 0)
                and (status <> 'partially_paid' or (paid_cents > 0 and paid_cents < payable_cents))
                and (status <> 'paid' or paid_cents = payable_cents)
            )
            SQL);
        // A vendor's invoice is recorded once (among the bills that stand).
        DB::statement("create unique index vendor_bills_vendor_invoice_unique on vendor_bills (vendor_id, lower(vendor_invoice_no)) where status <> 'void'");

        Schema::create('vendor_bill_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('vendor_bill_id')->index();
            $table->unsignedSmallInteger('position')->default(0);
            // `goods`: a goods receipt line being billed; `expense`: charged straight to an account.
            $table->string('kind', 8);
            $table->string('description', 500);
            $table->ulid('goods_receipt_line_id')->nullable()->index();
            // The account debited: an expense line's own; a goods line that never went on the shelf.
            $table->ulid('account_id')->nullable();
            $table->quantity('quantity');
            $table->cents('unit_cost_cents');
            $table->string('tax_class', 16);
            // As the vendor billed it (VAT-inclusive where the bill's prices are).
            $table->cents('line_total_cents');
            // Net of VAT: what the line debits.
            $table->cents('net_cents');
            // For a stocked goods line: what the receipt credited GR/IR with, which the bill now clears.
            $table->cents('received_value_cents')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['vendor_bill_id', 'organization_id'])->references(['id', 'organization_id'])->on('vendor_bills')->restrictOnDelete();
            $table->foreign(['goods_receipt_line_id', 'organization_id'])->references(['id', 'organization_id'])->on('goods_receipt_lines')->restrictOnDelete();
            $table->foreign(['account_id', 'organization_id'])->references(['id', 'organization_id'])->on('accounts')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table vendor_bill_lines add constraint vendor_bill_lines_values_check check (
                kind in ('goods', 'expense')
                and tax_class in ('vatable', 'vat_exempt', 'zero_rated')
                and quantity > 0 and unit_cost_cents >= 0
                and line_total_cents = round(quantity * unit_cost_cents)
                and net_cents between 0 and line_total_cents
                and ((kind = 'goods') = (goods_receipt_line_id is not null))
                and (kind = 'goods' or account_id is not null)
                and (received_value_cents is null or (kind = 'goods' and received_value_cents >= 0))
                and (kind = 'expense' or received_value_cents is not null or account_id is not null)
            )
            SQL);

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('vendor_id')->index();
            $table->string('number', 32);
            $table->string('status', 8)->default('posted');
            $table->string('method', 16);
            $table->string('reference_no', 64)->nullable();
            $table->cents('amount_cents');
            // A business date, Asia/Manila (R9).
            $table->date('paid_on');
            $table->ulid('paid_by')->nullable();
            $table->string('paid_by_name');
            $table->text('notes')->default('');
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'number']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'vendor_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['vendor_id', 'organization_id'])->references(['id', 'organization_id'])->on('vendors')->restrictOnDelete();
            $table->foreign(['paid_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table vendor_payments add constraint vendor_payments_values_check check (
                status in ('posted', 'void') and ((status = 'void') = (voided_at is not null))
                and method in ('cash', 'gcash', 'maya', 'card', 'bank_transfer', 'check')
                and (method = 'cash' or coalesce(reference_no, '') <> '')
                and amount_cents > 0
            )
            SQL);

        Schema::create('vendor_payment_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('vendor_payment_id')->index();
            $table->ulid('vendor_bill_id')->index();
            $table->ulid('vendor_id');
            $table->cents('amount_cents');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['vendor_payment_id', 'vendor_bill_id']);
            $table->foreign(['vendor_payment_id', 'organization_id'])->references(['id', 'organization_id'])->on('vendor_payments')->restrictOnDelete();
            $table->foreign(['vendor_payment_id', 'vendor_id'])->references(['id', 'vendor_id'])->on('vendor_payments')->restrictOnDelete();
            $table->foreign(['vendor_bill_id', 'vendor_id'])->references(['id', 'vendor_id'])->on('vendor_bills')->restrictOnDelete();
        });
        DB::statement('alter table vendor_payment_allocations add constraint vendor_payment_allocations_amount_check check (amount_cents > 0)');

        AppendOnly::protect('vendor_bill_lines');
        AppendOnly::protect('vendor_payment_allocations');

        // The payables subledger key on a journal line, beside the customer's.
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->ulid('vendor_id')->nullable()->index();
            $table->foreign(['vendor_id', 'organization_id'])->references(['id', 'organization_id'])->on('vendors')->restrictOnDelete();
        });

        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count', 'payment', 'detailing_booking', 'prepaid_package', 'vendor_bill', 'vendor_payment', 'credit_note', 'refund'))");

        DB::unprepared(<<<'SQL'
            -- R7: a posted bill keeps everything but its payment status and the void stamp.
            create or replace function vendor_bills_keep_posted() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'vendor bills are never deleted; void instead' using errcode = 'restrict_violation';
                end if;
                if old.status = 'void' then
                    raise exception 'vendor bill % is void; it never changes again', old.number using errcode = 'restrict_violation';
                end if;
                if (to_jsonb(new) - 'status' - 'paid_cents' - 'voided_at' - 'voided_by_name' - 'void_reason' - 'updated_at')
                    is distinct from (to_jsonb(old) - 'status' - 'paid_cents' - 'voided_at' - 'voided_by_name' - 'void_reason' - 'updated_at') then
                    raise exception 'vendor bill % has been posted; only its payment status may change', old.number
                        using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger vendor_bills_keep_posted
                before update or delete on vendor_bills
                for each row execute function vendor_bills_keep_posted();

            -- A receipt line is billed once, while its receipt stands.
            create or replace function vendor_bill_lines_fit() returns trigger language plpgsql as $$
            declare
                receipt_id char(26);
                receipt_status text;
            begin
                if new.goods_receipt_line_id is null then
                    return new;
                end if;
                select goods_receipt_id into receipt_id from goods_receipt_lines where id = new.goods_receipt_line_id;
                select status into receipt_status from goods_receipts where id = receipt_id for update;
                if receipt_status <> 'posted' then
                    raise exception 'a voided goods receipt is not billed' using errcode = 'check_violation';
                end if;
                if exists (
                    select 1 from vendor_bill_lines l join vendor_bills b on b.id = l.vendor_bill_id
                    where l.goods_receipt_line_id = new.goods_receipt_line_id and b.status <> 'void' and l.id <> new.id
                ) then
                    raise exception 'that goods receipt line is already on a standing vendor bill' using errcode = 'unique_violation';
                end if;
                return new;
            end
            $$;
            create trigger vendor_bill_lines_fit
                before insert on vendor_bill_lines
                for each row execute function vendor_bill_lines_fit();

            create or replace function vendor_payments_keep_posted() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'vendor payments are never deleted; void instead' using errcode = 'restrict_violation';
                end if;
                if old.status = 'void' or (
                    (to_jsonb(new) - 'status' - 'voided_at' - 'voided_by_name' - 'void_reason' - 'updated_at')
                    is distinct from (to_jsonb(old) - 'status' - 'voided_at' - 'voided_by_name' - 'void_reason' - 'updated_at')
                ) then
                    raise exception 'vendor payment % has been posted; it can only be voided, once', old.number
                        using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger vendor_payments_keep_posted
                before update or delete on vendor_payments
                for each row execute function vendor_payments_keep_posted();

            -- An allocation fits: a posted payment, a standing unpaid bill, never past the payment.
            create or replace function vendor_payment_allocations_fit() returns trigger language plpgsql as $$
            declare
                payment_row vendor_payments%rowtype;
                bill_status text;
                allocated bigint;
            begin
                select * into payment_row from vendor_payments where id = new.vendor_payment_id for update;
                if payment_row.status <> 'posted' then
                    raise exception 'vendor payment % is void; it cannot be allocated', payment_row.number using errcode = 'check_violation';
                end if;
                select status into bill_status from vendor_bills where id = new.vendor_bill_id;
                if bill_status not in ('posted', 'partially_paid') then
                    raise exception 'only a posted, unpaid vendor bill takes a payment' using errcode = 'check_violation';
                end if;
                select coalesce(sum(amount_cents), 0) into allocated from vendor_payment_allocations where vendor_payment_id = new.vendor_payment_id;
                if allocated + new.amount_cents > payment_row.amount_cents then
                    raise exception 'vendor payment % has only % centavos left to allocate', payment_row.number, payment_row.amount_cents - allocated
                        using errcode = 'check_violation';
                end if;
                return new;
            end
            $$;
            create trigger vendor_payment_allocations_fit
                before insert on vendor_payment_allocations
                for each row execute function vendor_payment_allocations_fit();

            -- paid_cents = what the standing payments allocate. Re-reads the CURRENT rows at commit.
            create or replace function vendor_bill_settlement_holds(target char(26)) returns void language plpgsql as $$
            declare
                stored bigint;
                standing bigint;
            begin
                select paid_cents into stored from vendor_bills where id = target;
                if stored is null then
                    return;
                end if;
                select coalesce(sum(a.amount_cents), 0) into standing
                    from vendor_payment_allocations a join vendor_payments p on p.id = a.vendor_payment_id
                    where a.vendor_bill_id = target and p.status = 'posted';
                if stored <> standing then
                    raise exception 'vendor bill % records % centavos paid, but its standing payments allocate %', target, stored, standing
                        using errcode = 'check_violation';
                end if;
            end
            $$;

            create or replace function vendor_bills_settlement_check() returns trigger language plpgsql as $$
            begin
                perform vendor_bill_settlement_holds(new.id);
                return null;
            end
            $$;
            create constraint trigger vendor_bills_settlement_check
                after insert or update on vendor_bills deferrable initially deferred
                for each row execute function vendor_bills_settlement_check();

            create or replace function vendor_payment_allocations_settlement_check() returns trigger language plpgsql as $$
            begin
                perform vendor_bill_settlement_holds(new.vendor_bill_id);
                return null;
            end
            $$;
            create constraint trigger vendor_payment_allocations_settlement_check
                after insert on vendor_payment_allocations deferrable initially deferred
                for each row execute function vendor_payment_allocations_settlement_check();

            create or replace function vendor_payments_settlement_check() returns trigger language plpgsql as $$
            declare
                target char(26);
            begin
                for target in select distinct vendor_bill_id from vendor_payment_allocations where vendor_payment_id = new.id loop
                    perform vendor_bill_settlement_holds(target);
                end loop;
                return null;
            end
            $$;
            create constraint trigger vendor_payments_settlement_check
                after update on vendor_payments deferrable initially deferred
                for each row execute function vendor_payments_settlement_check();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists vendor_payments_settlement_check on vendor_payments;
            drop function if exists vendor_payments_settlement_check();
            drop trigger if exists vendor_payment_allocations_settlement_check on vendor_payment_allocations;
            drop function if exists vendor_payment_allocations_settlement_check();
            drop trigger if exists vendor_bills_settlement_check on vendor_bills;
            drop function if exists vendor_bills_settlement_check();
            drop function if exists vendor_bill_settlement_holds(char);
            drop trigger if exists vendor_payment_allocations_fit on vendor_payment_allocations;
            drop function if exists vendor_payment_allocations_fit();
            drop trigger if exists vendor_payments_keep_posted on vendor_payments;
            drop function if exists vendor_payments_keep_posted();
            drop trigger if exists vendor_bill_lines_fit on vendor_bill_lines;
            drop function if exists vendor_bill_lines_fit();
            drop trigger if exists vendor_bills_keep_posted on vendor_bills;
            drop function if exists vendor_bills_keep_posted();
            SQL);
        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count', 'payment', 'detailing_booking', 'prepaid_package'))");
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['vendor_id', 'organization_id']);
            $table->dropColumn('vendor_id');
        });
        AppendOnly::unprotect('vendor_payment_allocations');
        AppendOnly::unprotect('vendor_bill_lines');
        Schema::dropIfExists('vendor_payment_allocations');
        Schema::dropIfExists('vendor_payments');
        Schema::dropIfExists('vendor_bill_lines');
        Schema::dropIfExists('vendor_bills');
        DB::statement('alter table goods_receipt_lines drop constraint if exists goods_receipt_lines_id_organization_unique');
        DB::statement('alter table vendors drop constraint vendors_tax_check');
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['registered_name', 'tin', 'address', 'is_vat_registered', 'payment_terms_days', 'default_atc_code', 'default_withholding_rate_pct']);
        });
    }
};
