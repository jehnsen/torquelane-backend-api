<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments and their allocations (Phase 7).
 *
 * Invariants the database holds:
 *  - a payment is numbered from the `payment` series at creation (R8) and is
 *    an issued document (R7): never edited or deleted, only voided, once;
 *  - an allocation (some of a payment applied to one invoice of the SAME
 *    account) is append-only. A payment's allocations never add up to more
 *    than the payment, and only a posted payment is allocated, only to an
 *    issued, unpaid invoice. What a payment has not allocated is the
 *    customer's credit;
 *  - an invoice's `paid_cents` is always the sum of the allocations of its
 *    payments that still stand. Checked at COMMIT (deferred constraint
 *    triggers on all three tables), so the action that moves money must keep
 *    the invoice in step in the same transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
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
            $table->date('received_on');
            $table->ulid('received_by')->nullable();
            $table->string('received_by_name');
            $table->text('notes')->default('');
            $table->timestampTz('voided_at')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'number']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'customer_account_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['received_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table payments add constraint payments_values_check check (
                status in ('posted', 'void') and ((status = 'void') = (voided_at is not null))
                and method in ('cash', 'gcash', 'maya', 'card', 'bank_transfer', 'check')
                and (method = 'cash' or coalesce(reference_no, '') <> '')
                and amount_cents > 0
            )
            SQL);

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('payment_id')->index();
            $table->ulid('invoice_id')->index();
            $table->ulid('customer_account_id');
            $table->cents('amount_cents');
            $table->date('allocated_on');
            $table->timestampTz('allocated_at');
            $table->ulid('allocated_by')->nullable();
            $table->string('allocated_by_name');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['payment_id', 'organization_id'])->references(['id', 'organization_id'])->on('payments')->restrictOnDelete();
            $table->foreign(['payment_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('payments')->restrictOnDelete();
            $table->foreign(['invoice_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['allocated_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement('alter table payment_allocations add constraint payment_allocations_amount_check check (amount_cents > 0)');
        AppendOnly::protect('payment_allocations');

        DB::unprepared(<<<'SQL'
            -- R7: a payment can only be voided, once; never edited or deleted.
            create or replace function payments_keep_posted() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'payments are never deleted; void instead' using errcode = 'restrict_violation';
                end if;
                if old.status = 'void' or (
                    new.branch_id is distinct from old.branch_id
                    or new.customer_account_id is distinct from old.customer_account_id
                    or new.number is distinct from old.number
                    or new.method is distinct from old.method
                    or new.reference_no is distinct from old.reference_no
                    or new.amount_cents is distinct from old.amount_cents
                    or new.received_on is distinct from old.received_on
                    or new.received_by is distinct from old.received_by
                    or new.received_by_name is distinct from old.received_by_name
                    or new.notes is distinct from old.notes
                ) then
                    raise exception 'payment % has been posted; it can only be voided, once', old.number
                        using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger payments_keep_posted
                before update or delete on payments
                for each row execute function payments_keep_posted();

            -- An allocation fits: a posted payment, an issued invoice, never past the payment.
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
            create trigger payment_allocations_fit
                before insert on payment_allocations
                for each row execute function payment_allocations_fit();

            -- paid_cents = what the standing payments have allocated. Re-reads the
            -- CURRENT rows: a deferred trigger sees the row as it was queued.
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

            create or replace function invoices_settlement_check() returns trigger language plpgsql as $$
            begin
                perform invoice_settlement_holds(new.id);
                return null;
            end
            $$;
            create constraint trigger invoices_settlement_check
                after insert or update on invoices deferrable initially deferred
                for each row execute function invoices_settlement_check();

            create or replace function payment_allocations_settlement_check() returns trigger language plpgsql as $$
            begin
                perform invoice_settlement_holds(new.invoice_id);
                return null;
            end
            $$;
            create constraint trigger payment_allocations_settlement_check
                after insert on payment_allocations deferrable initially deferred
                for each row execute function payment_allocations_settlement_check();

            create or replace function payments_settlement_check() returns trigger language plpgsql as $$
            declare
                target char(26);
            begin
                for target in select distinct invoice_id from payment_allocations where payment_id = new.id loop
                    perform invoice_settlement_holds(target);
                end loop;
                return null;
            end
            $$;
            create constraint trigger payments_settlement_check
                after update on payments deferrable initially deferred
                for each row execute function payments_settlement_check();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists payments_settlement_check on payments;
            drop function if exists payments_settlement_check();
            drop trigger if exists payment_allocations_settlement_check on payment_allocations;
            drop function if exists payment_allocations_settlement_check();
            drop trigger if exists invoices_settlement_check on invoices;
            drop function if exists invoices_settlement_check();
            drop function if exists invoice_settlement_holds(char);
            drop trigger if exists payment_allocations_fit on payment_allocations;
            drop function if exists payment_allocations_fit();
            drop trigger if exists payments_keep_posted on payments;
            drop function if exists payments_keep_posted();
            SQL);
        AppendOnly::unprotect('payment_allocations');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
