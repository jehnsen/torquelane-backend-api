<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes, refunds and creditable withholding (Phase 12): the ways an
 * issued invoice is settled other than by money, and the way money goes back.
 *
 * An invoice is now settled by three things: what its standing payments
 * allocate (`paid_cents`), what its standing credit notes take off it
 * (`credited_cents`) and what the customer withheld at source and certified on
 * a BIR 2307 (`withheld_cents`). Its status follows their sum.
 *
 * Invariants the database holds:
 *  - a credit note is numbered from the `credit_note` series when issued (R8),
 *    names one issued invoice of the same account (composite key), and is
 *    then immutable (R7): voided once, never edited or deleted; its lines are
 *    append-only. Its total splits into what came off the invoice
 *    (`applied_cents`) and what became the customer's credit (`credit_cents`);
 *  - a refund is numbered from the `refund` series, gives back either the
 *    unallocated money of ONE payment or the credit of ONE credit note, and
 *    never more than that source still holds (trigger, the source locked);
 *  - a payment's allocations and refunds together never exceed the payment;
 *  - a withholding certificate takes its amount off one invoice of the same
 *    account; posted, then voided at most once;
 *  - `paid_cents`, `credited_cents` and `withheld_cents` equal what stands
 *    against the invoice, checked at COMMIT (deferred constraint triggers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->cents('credited_cents')->default(0);
            $table->cents('withheld_cents')->default(0);
        });
        DB::statement('alter table invoices drop constraint invoices_money_check');
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_money_check check (
                vatable_sales_cents >= 0 and vat_exempt_sales_cents >= 0 and zero_rated_sales_cents >= 0
                and non_vat_sales_cents >= 0 and discount_total_cents >= 0 and vat_amount_cents >= 0
                and total_due_cents = vatable_sales_cents + vat_amount_cents + vat_exempt_sales_cents + zero_rated_sales_cents + non_vat_sales_cents
                and paid_cents >= 0 and credited_cents >= 0 and withheld_cents >= 0
                and paid_cents + credited_cents + withheld_cents <= total_due_cents
                and (seller_vat_registered or (vat_amount_cents = 0 and vatable_sales_cents = 0))
            )
            SQL);
        DB::statement('alter table invoices drop constraint invoices_paid_status_check');
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_paid_status_check check (
                (status not in ('draft', 'issued', 'void') or paid_cents + credited_cents + withheld_cents = 0)
                and (status <> 'partially_paid' or (paid_cents + credited_cents + withheld_cents > 0 and paid_cents + credited_cents + withheld_cents < total_due_cents))
                and (status <> 'paid' or paid_cents + credited_cents + withheld_cents = total_due_cents)
            )
            SQL);
        DB::statement('alter table invoice_lines add constraint invoice_lines_id_organization_unique unique (id, organization_id)');

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('customer_account_id')->index();
            $table->ulid('invoice_id')->index();
            $table->string('number', 32);
            $table->string('status', 8)->default('issued');
            // A business date, Asia/Manila (R9).
            $table->date('issued_on');
            $table->text('reason');

            // The invoice's VAT treatment, repeated: a credit undoes a sale the way it was made.
            $table->boolean('seller_vat_registered');
            $table->boolean('prices_include_vat');
            $table->rate('vat_rate_pct');
            $table->cents('vatable_sales_cents')->default(0);
            $table->cents('vat_exempt_sales_cents')->default(0);
            $table->cents('zero_rated_sales_cents')->default(0);
            $table->cents('non_vat_sales_cents')->default(0);
            $table->cents('vat_amount_cents')->default(0);
            $table->cents('total_cents');
            // What came off the invoice's balance, and what became the customer's credit.
            $table->cents('applied_cents');
            $table->cents('credit_cents');

            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'number']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'customer_account_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['invoice_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table credit_notes add constraint credit_notes_values_check check (
                status in ('issued', 'void') and ((status = 'void') = (voided_at is not null))
                and vatable_sales_cents >= 0 and vat_exempt_sales_cents >= 0 and zero_rated_sales_cents >= 0
                and non_vat_sales_cents >= 0 and vat_amount_cents >= 0
                and total_cents = vatable_sales_cents + vat_amount_cents + vat_exempt_sales_cents + zero_rated_sales_cents + non_vat_sales_cents
                and total_cents > 0 and applied_cents >= 0 and credit_cents >= 0
                and applied_cents + credit_cents = total_cents
                and (seller_vat_registered or (vat_amount_cents = 0 and vatable_sales_cents = 0))
            )
            SQL);

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('credit_note_id')->index();
            $table->unsignedSmallInteger('position')->default(0);
            // The invoice line being credited; none for an allowance on the invoice as a whole.
            $table->ulid('invoice_line_id')->nullable()->index();
            $table->string('description', 500);
            $table->quantity('quantity');
            $table->cents('unit_price_cents');
            $table->string('tax_class', 16);
            $table->cents('line_total_cents');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['credit_note_id', 'organization_id'])->references(['id', 'organization_id'])->on('credit_notes')->restrictOnDelete();
            $table->foreign(['invoice_line_id', 'organization_id'])->references(['id', 'organization_id'])->on('invoice_lines')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table credit_note_lines add constraint credit_note_lines_values_check check (
                tax_class in ('vatable', 'vat_exempt', 'zero_rated')
                and quantity > 0 and unit_price_cents >= 0
                and line_total_cents = round(quantity * unit_price_cents)
            )
            SQL);
        AppendOnly::protect('credit_note_lines');

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('customer_account_id')->index();
            $table->string('number', 32);
            $table->string('status', 8)->default('posted');
            $table->string('method', 16);
            $table->string('reference_no', 64)->nullable();
            $table->cents('amount_cents');
            // A business date, Asia/Manila (R9).
            $table->date('refunded_on');
            // Where the money being given back sat: a payment's unallocated part, or a credit note's credit.
            $table->ulid('payment_id')->nullable()->index();
            $table->ulid('credit_note_id')->nullable()->index();
            $table->text('reason');
            $table->ulid('refunded_by')->nullable();
            $table->string('refunded_by_name');
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'number']);
            $table->unique(['id', 'organization_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['payment_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('payments')->restrictOnDelete();
            $table->foreign(['credit_note_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('credit_notes')->restrictOnDelete();
            $table->foreign(['refunded_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table refunds add constraint refunds_values_check check (
                status in ('posted', 'void') and ((status = 'void') = (voided_at is not null))
                and method in ('cash', 'gcash', 'maya', 'card', 'bank_transfer', 'check')
                and (method = 'cash' or coalesce(reference_no, '') <> '')
                and amount_cents > 0
                and ((payment_id is null) <> (credit_note_id is null))
            )
            SQL);

        Schema::create('withholding_certificates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('customer_account_id')->index();
            $table->ulid('invoice_id')->index();
            $table->string('status', 8)->default('posted');
            // The customer's own certificate number, where their 2307 carries one.
            $table->string('certificate_no', 64)->nullable();
            $table->string('atc_code', 8);
            $table->rate('rate_pct');
            $table->cents('tax_base_cents');
            $table->cents('amount_cents');
            // Business dates, Asia/Manila (R9): when it was taken off the invoice, and the period the 2307 covers.
            $table->date('received_on');
            $table->date('period_from');
            $table->date('period_to');
            $table->text('notes')->default('');
            $table->ulid('recorded_by')->nullable();
            $table->string('recorded_by_name');
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['invoice_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['recorded_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table withholding_certificates add constraint withholding_certificates_values_check check (
                status in ('posted', 'void') and ((status = 'void') = (voided_at is not null))
                and atc_code <> '' and rate_pct > 0 and rate_pct <= 100
                and tax_base_cents > 0 and amount_cents > 0 and amount_cents <= tax_base_cents
                and period_to >= period_from
            )
            SQL);

        DB::unprepared(<<<'SQL'
            -- R7: issued once, voided once; nothing else about it ever moves.
            create or replace function settlement_documents_keep_posted() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception '% rows are never deleted; void instead', tg_table_name using errcode = 'restrict_violation';
                end if;
                if old.status = 'void' or (
                    (to_jsonb(new) - 'status' - 'voided_at' - 'voided_by_name' - 'void_reason' - 'updated_at')
                    is distinct from (to_jsonb(old) - 'status' - 'voided_at' - 'voided_by_name' - 'void_reason' - 'updated_at')
                ) then
                    raise exception 'a posted % can only be voided, once', tg_table_name using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger credit_notes_keep_posted
                before update or delete on credit_notes
                for each row execute function settlement_documents_keep_posted();
            create trigger refunds_keep_posted
                before update or delete on refunds
                for each row execute function settlement_documents_keep_posted();
            create trigger withholding_certificates_keep_posted
                before update or delete on withholding_certificates
                for each row execute function settlement_documents_keep_posted();

            -- A refund gives back no more than its source still holds.
            create or replace function refunds_fit() returns trigger language plpgsql as $$
            declare
                payment_row payments%rowtype;
                note_row credit_notes%rowtype;
                held bigint;
                refunded bigint;
            begin
                if new.payment_id is not null then
                    select * into payment_row from payments where id = new.payment_id for update;
                    if payment_row.status <> 'posted' then
                        raise exception 'payment % is void; there is nothing of it to refund', payment_row.number using errcode = 'check_violation';
                    end if;
                    select payment_row.amount_cents - coalesce(sum(amount_cents), 0) into held from payment_allocations where payment_id = new.payment_id;
                    select coalesce(sum(amount_cents), 0) into refunded from refunds where payment_id = new.payment_id and status = 'posted' and id <> new.id;
                else
                    select * into note_row from credit_notes where id = new.credit_note_id for update;
                    if note_row.status <> 'issued' then
                        raise exception 'credit note % is void; there is nothing of it to refund', note_row.number using errcode = 'check_violation';
                    end if;
                    held := note_row.credit_cents;
                    select coalesce(sum(amount_cents), 0) into refunded from refunds where credit_note_id = new.credit_note_id and status = 'posted' and id <> new.id;
                end if;
                if refunded + new.amount_cents > held then
                    raise exception 'only % centavos are left to refund', held - refunded using errcode = 'check_violation';
                end if;
                return new;
            end
            $$;
            create trigger refunds_fit
                before insert on refunds
                for each row execute function refunds_fit();

            -- An allocation fits: a posted payment, an issued invoice, never past what the
            -- payment still holds once its refunds are taken off.
            create or replace function payment_allocations_fit() returns trigger language plpgsql as $$
            declare
                payment_row payments%rowtype;
                invoice_status text;
                allocated bigint;
                refunded bigint;
            begin
                select * into payment_row from payments where id = new.payment_id for update;
                if payment_row.status <> 'posted' then
                    raise exception 'payment % is void; it cannot be allocated', payment_row.number using errcode = 'check_violation';
                end if;
                select status into invoice_status from invoices where id = new.invoice_id;
                if invoice_status not in ('issued', 'partially_paid') then
                    raise exception 'only an issued, unpaid invoice takes a payment' using errcode = 'check_violation';
                end if;
                select coalesce(sum(amount_cents), 0) into allocated from payment_allocations where payment_id = new.payment_id;
                select coalesce(sum(amount_cents), 0) into refunded from refunds where payment_id = new.payment_id and status = 'posted';
                if allocated + refunded + new.amount_cents > payment_row.amount_cents then
                    raise exception 'payment % has only % centavos left to allocate', payment_row.number, payment_row.amount_cents - allocated - refunded
                        using errcode = 'check_violation';
                end if;
                return new;
            end
            $$;

            -- What stands against an invoice is what the invoice records. Re-reads the
            -- CURRENT rows: a deferred trigger sees the row as it was queued.
            create or replace function invoice_settlement_holds(target char(26)) returns void language plpgsql as $$
            declare
                invoice_row invoices%rowtype;
                standing bigint;
            begin
                select * into invoice_row from invoices where id = target;
                if invoice_row.id is null then
                    return;
                end if;
                select coalesce(sum(a.amount_cents), 0) into standing
                    from payment_allocations a join payments p on p.id = a.payment_id
                    where a.invoice_id = target and p.status = 'posted';
                if invoice_row.paid_cents <> standing then
                    raise exception 'invoice % records % centavos paid, but its standing payments allocate %', target, invoice_row.paid_cents, standing
                        using errcode = 'check_violation';
                end if;
                select coalesce(sum(applied_cents), 0) into standing from credit_notes where invoice_id = target and status = 'issued';
                if invoice_row.credited_cents <> standing then
                    raise exception 'invoice % records % centavos credited, but its standing credit notes apply %', target, invoice_row.credited_cents, standing
                        using errcode = 'check_violation';
                end if;
                select coalesce(sum(amount_cents), 0) into standing from withholding_certificates where invoice_id = target and status = 'posted';
                if invoice_row.withheld_cents <> standing then
                    raise exception 'invoice % records % centavos withheld, but its standing certificates total %', target, invoice_row.withheld_cents, standing
                        using errcode = 'check_violation';
                end if;
            end
            $$;

            create or replace function invoice_credits_settlement_check() returns trigger language plpgsql as $$
            begin
                perform invoice_settlement_holds(new.invoice_id);
                return null;
            end
            $$;
            create constraint trigger credit_notes_settlement_check
                after insert or update on credit_notes deferrable initially deferred
                for each row execute function invoice_credits_settlement_check();
            create constraint trigger withholding_certificates_settlement_check
                after insert or update on withholding_certificates deferrable initially deferred
                for each row execute function invoice_credits_settlement_check();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists withholding_certificates_settlement_check on withholding_certificates;
            drop trigger if exists credit_notes_settlement_check on credit_notes;
            drop function if exists invoice_credits_settlement_check();
            drop trigger if exists refunds_fit on refunds;
            drop function if exists refunds_fit();
            drop trigger if exists withholding_certificates_keep_posted on withholding_certificates;
            drop trigger if exists refunds_keep_posted on refunds;
            drop trigger if exists credit_notes_keep_posted on credit_notes;
            drop function if exists settlement_documents_keep_posted();

            create or replace function invoice_settlement_holds(target char(26)) returns void language plpgsql as $$
            declare
                stored bigint;
                standing bigint;
            begin
                select paid_cents into stored from invoices where id = target;
                if stored is null then
                    return;
                end if;
                select coalesce(sum(a.amount_cents), 0) into standing
                    from payment_allocations a join payments p on p.id = a.payment_id
                    where a.invoice_id = target and p.status = 'posted';
                if stored <> standing then
                    raise exception 'invoice % records % centavos paid, but its standing payments allocate %', target, stored, standing
                        using errcode = 'check_violation';
                end if;
            end
            $$;

            create or replace function payment_allocations_fit() returns trigger language plpgsql as $$
            declare
                payment_row payments%rowtype;
                invoice_status text;
                allocated bigint;
            begin
                select * into payment_row from payments where id = new.payment_id for update;
                if payment_row.status <> 'posted' then
                    raise exception 'payment % is void; it cannot be allocated', payment_row.number using errcode = 'check_violation';
                end if;
                select status into invoice_status from invoices where id = new.invoice_id;
                if invoice_status not in ('issued', 'partially_paid') then
                    raise exception 'only an issued, unpaid invoice takes a payment' using errcode = 'check_violation';
                end if;
                select coalesce(sum(amount_cents), 0) into allocated from payment_allocations where payment_id = new.payment_id;
                if allocated + new.amount_cents > payment_row.amount_cents then
                    raise exception 'payment % has only % centavos left to allocate', payment_row.number, payment_row.amount_cents - allocated
                        using errcode = 'check_violation';
                end if;
                return new;
            end
            $$;
            SQL);
        Schema::dropIfExists('withholding_certificates');
        Schema::dropIfExists('refunds');
        AppendOnly::unprotect('credit_note_lines');
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
        DB::statement('alter table invoice_lines drop constraint if exists invoice_lines_id_organization_unique');
        DB::statement('alter table invoices drop constraint invoices_paid_status_check');
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_paid_status_check check (
                (status not in ('draft', 'issued', 'void') or paid_cents = 0)
                and (status <> 'partially_paid' or (paid_cents > 0 and paid_cents < total_due_cents))
                and (status <> 'paid' or paid_cents = total_due_cents)
            )
            SQL);
        DB::statement('alter table invoices drop constraint invoices_money_check');
        DB::statement(<<<'SQL'
            alter table invoices add constraint invoices_money_check check (
                vatable_sales_cents >= 0 and vat_exempt_sales_cents >= 0 and zero_rated_sales_cents >= 0
                and non_vat_sales_cents >= 0 and discount_total_cents >= 0 and vat_amount_cents >= 0
                and total_due_cents = vatable_sales_cents + vat_amount_cents + vat_exempt_sales_cents + zero_rated_sales_cents + non_vat_sales_cents
                and paid_cents between 0 and total_due_cents
                and (seller_vat_registered or (vat_amount_cents = 0 and vatable_sales_cents = 0))
            )
            SQL);
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['credited_cents', 'withheld_cents']);
        });
    }
};
