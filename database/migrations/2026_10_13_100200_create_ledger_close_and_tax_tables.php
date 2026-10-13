<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closing the books (Phase 12): general journal vouchers, bank
 * reconciliation, the year-end close, and VAT on prepaid packages.
 *
 * Invariants the database holds:
 *  - a manual journal is the document behind a typed-in journal entry: posted
 *    once, reversed at most once, never edited or deleted;
 *  - a journal line is cleared by at most ONE bank reconciliation (unique),
 *    and only while that reconciliation is open may its lines change; a
 *    completed reconciliation is final;
 *  - a fiscal year is closed once (unique per organization), and the row is
 *    never edited or deleted afterwards;
 *  - a prepaid package's VAT never exceeds its price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_journals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            // A business date, Asia/Manila (R9).
            $table->date('entry_date');
            $table->string('reference', 64)->default('');
            $table->text('memo');
            $table->string('status', 8)->default('posted');
            $table->cents('total_cents');
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampTz('reversed_at')->nullable();
            $table->string('reversed_by_name')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("alter table manual_journals add constraint manual_journals_values_check check (status in ('posted', 'reversed') and ((status = 'reversed') = (reversed_at is not null)) and total_cents > 0 and memo <> '')");

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            // The cash-side ledger account being agreed with its statement.
            $table->ulid('account_id')->index();
            // A business date, Asia/Manila (R9): the statement's closing date.
            $table->date('statement_date');
            // Signed: an overdrawn account's statement closes below zero.
            $table->cents('statement_balance_cents');
            $table->string('status', 10)->default('open');
            // Fixed when completed: what the cleared lines came to, and what was still outstanding in the books.
            $table->cents('cleared_balance_cents')->nullable();
            $table->cents('book_balance_cents')->nullable();
            $table->text('notes')->default('');
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampTz('completed_at')->nullable();
            $table->string('completed_by_name')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['account_id', 'organization_id'])->references(['id', 'organization_id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("alter table bank_reconciliations add constraint bank_reconciliations_values_check check (status in ('open', 'completed') and ((status = 'completed') = (completed_at is not null)) and ((status = 'completed') = (cleared_balance_cents is not null)))");
        // One statement at a time per account.
        DB::statement("create unique index bank_reconciliations_one_open on bank_reconciliations (account_id) where status = 'open'");

        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('bank_reconciliation_id')->index();
            $table->ulid('journal_line_id');
            $table->timestampTz('created_at')->useCurrent();

            // A line appears on one statement, once.
            $table->unique('journal_line_id');
            $table->foreign(['bank_reconciliation_id', 'organization_id'])->references(['id', 'organization_id'])->on('bank_reconciliations')->cascadeOnDelete();
            $table->foreign('journal_line_id')->references('id')->on('journal_lines')->restrictOnDelete();
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            // The calendar year (the BIR default); its last day is 31 December.
            $table->unsignedSmallInteger('year');
            $table->string('status', 8)->default('closed');
            // Signed: a loss year closes with a negative figure.
            $table->cents('net_income_cents');
            $table->timestampTz('closed_at');
            $table->ulid('closed_by')->nullable();
            $table->string('closed_by_name');
            $table->timestampsTz();

            $table->unique(['organization_id', 'year']);
            $table->unique(['id', 'organization_id']);
            $table->foreign(['closed_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("alter table fiscal_years add constraint fiscal_years_values_check check (status = 'closed' and year between 2000 and 2999)");

        // The VAT inside a prepaid package's price (it is sold VAT-inclusive). Nothing for a package
        // sold before this, or by a branch that is not VAT-registered.
        Schema::table('prepaid_packages', function (Blueprint $table) {
            $table->cents('vat_cents')->default(0);
            $table->rate('vat_rate_pct')->default(0);
        });
        DB::statement('alter table prepaid_packages add constraint prepaid_packages_vat_check check (vat_cents >= 0 and vat_cents < price_cents and vat_rate_pct >= 0)');

        DB::unprepared(<<<'SQL'
            create or replace function manual_journals_keep_posted() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'a posted journal voucher is never deleted; reverse it' using errcode = 'restrict_violation';
                end if;
                if old.status = 'reversed' or (
                    (to_jsonb(new) - 'status' - 'reversed_at' - 'reversed_by_name' - 'reversal_reason' - 'updated_at')
                    is distinct from (to_jsonb(old) - 'status' - 'reversed_at' - 'reversed_by_name' - 'reversal_reason' - 'updated_at')
                ) then
                    raise exception 'a posted journal voucher can only be reversed, once' using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger manual_journals_keep_posted
                before update or delete on manual_journals
                for each row execute function manual_journals_keep_posted();

            -- A completed reconciliation is final; an open one may be discarded.
            create or replace function bank_reconciliations_keep_completed() returns trigger language plpgsql as $$
            begin
                if old.status = 'completed' then
                    raise exception 'a completed bank reconciliation is final' using errcode = 'restrict_violation';
                end if;
                if tg_op = 'DELETE' then
                    return old;
                end if;
                if new.account_id is distinct from old.account_id then
                    raise exception 'a bank reconciliation keeps its account' using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger bank_reconciliations_keep_completed
                before update or delete on bank_reconciliations
                for each row execute function bank_reconciliations_keep_completed();

            -- Lines are ticked and unticked only while the statement is open, and only lines of its account.
            create or replace function bank_reconciliation_lines_fit() returns trigger language plpgsql as $$
            declare
                parent bank_reconciliations%rowtype;
                line_account char(26);
            begin
                select * into parent from bank_reconciliations
                    where id = case when tg_op = 'DELETE' then old.bank_reconciliation_id else new.bank_reconciliation_id end;
                -- The parent is gone when an open reconciliation is being discarded (cascade).
                if parent.id is null then
                    return case when tg_op = 'DELETE' then old else new end;
                end if;
                if parent.status <> 'open' then
                    raise exception 'a completed bank reconciliation is final' using errcode = 'restrict_violation';
                end if;
                if tg_op = 'DELETE' then
                    return old;
                end if;
                if tg_op = 'UPDATE' then
                    raise exception 'a cleared line is removed, not edited' using errcode = 'restrict_violation';
                end if;
                select account_id into line_account from journal_lines where id = new.journal_line_id;
                if line_account is distinct from parent.account_id then
                    raise exception 'that journal line is not on the account being reconciled' using errcode = 'check_violation';
                end if;
                return new;
            end
            $$;
            create trigger bank_reconciliation_lines_fit
                before insert or update or delete on bank_reconciliation_lines
                for each row execute function bank_reconciliation_lines_fit();

            create or replace function fiscal_years_keep_closed() returns trigger language plpgsql as $$
            begin
                raise exception 'a closed fiscal year is final' using errcode = 'restrict_violation';
            end
            $$;
            create trigger fiscal_years_keep_closed
                before update or delete on fiscal_years
                for each row execute function fiscal_years_keep_closed();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists fiscal_years_keep_closed on fiscal_years;
            drop function if exists fiscal_years_keep_closed();
            drop trigger if exists bank_reconciliation_lines_fit on bank_reconciliation_lines;
            drop function if exists bank_reconciliation_lines_fit();
            drop trigger if exists bank_reconciliations_keep_completed on bank_reconciliations;
            drop function if exists bank_reconciliations_keep_completed();
            drop trigger if exists manual_journals_keep_posted on manual_journals;
            drop function if exists manual_journals_keep_posted();
            SQL);
        DB::statement('alter table prepaid_packages drop constraint prepaid_packages_vat_check');
        Schema::table('prepaid_packages', function (Blueprint $table) {
            $table->dropColumn(['vat_cents', 'vat_rate_pct']);
        });
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('manual_journals');
    }
};
