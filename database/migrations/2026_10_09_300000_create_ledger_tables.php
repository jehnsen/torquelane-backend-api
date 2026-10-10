<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The general ledger (Phase 8): a chart of accounts per organization, the
 * posting rules that map an event or category to an account, monthly periods,
 * and an append-only, numbered, balanced double-entry journal.
 *
 * Invariants the database holds:
 *  - a journal entry and its lines are append-only (R7): a correction is a
 *    reversing entry that names the one it undoes (`reversal_of_id`, once);
 *  - every entry balances: at COMMIT (deferred constraint triggers) its debits
 *    equal its credits and it has at least two lines. A line carries a debit
 *    or a credit, never both, never negative;
 *  - every event posts once per source: (event, source type, source id) is
 *    unique, so a retried or backfilled posting cannot double up;
 *  - a stock move is posted on at most one line (partial unique index);
 *  - an entry is dated inside its period, and a closed period takes no new
 *    entry; a closed period is never edited again;
 *  - an account that has been posted to keeps its code, type and side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('code', 16);
            $table->string('name', 120);
            $table->string('type', 12);
            // Which side increases it. A contra account (sales discounts) is revenue on the debit side.
            $table->string('normal_side', 6);
            $table->boolean('is_active')->default(true);
            // Seeded with the chart; a system account is renamed or deactivated, never deleted.
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('description')->default('');
            $table->timestampsTz();

            $table->unique(['organization_id', 'code']);
            $table->unique(['id', 'organization_id']);
        });
        DB::statement("alter table accounts add constraint accounts_values_check check (type in ('asset', 'liability', 'equity', 'revenue', 'expense') and normal_side in ('debit', 'credit') and code <> '' and name <> '')");

        Schema::create('posting_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('rule_key', 48);
            $table->ulid('account_id')->index();
            $table->timestampsTz();

            $table->unique(['organization_id', 'rule_key']);
            $table->foreign(['account_id', 'organization_id'])->references(['id', 'organization_id'])->on('accounts')->restrictOnDelete();
        });

        Schema::create('periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            // 'YYYY-MM' (Asia/Manila calendar month).
            $table->string('period_key', 7);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 6)->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->ulid('closed_by')->nullable();
            $table->string('closed_by_name')->nullable();
            // The checklist as it stood when the period was closed.
            $table->jsonb('close_checklist')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'period_key']);
            $table->unique(['id', 'organization_id']);
            $table->foreign(['closed_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table periods add constraint periods_values_check check (
                status in ('open', 'closed') and ends_on >= starts_on
                and ((status = 'closed') = (closed_at is not null))
            )
            SQL);

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            // A transfer touches two branches: the one the goods left is `branch_id`, the one they reached this.
            $table->ulid('counter_branch_id')->nullable();
            $table->string('number', 32);
            // A business date, Asia/Manila (R9).
            $table->date('entry_date')->index();
            $table->ulid('period_id')->index();
            $table->string('event', 32);
            $table->string('source_type', 32);
            $table->ulid('source_id');
            // The number of the document it came from (INV-…, PAY-…, GR-…), for people.
            $table->string('reference', 64)->default('');
            $table->text('memo')->default('');
            // Set on payment events, so the daily sales report can split receipts by method.
            $table->string('payment_method', 16)->nullable();
            $table->ulid('reversal_of_id')->nullable();
            $table->cents('total_cents');
            $table->ulid('posted_by')->nullable();
            $table->string('posted_by_name');
            $table->timestampTz('posted_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['organization_id', 'number']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['organization_id', 'event', 'source_type', 'source_id']);
            $table->index(['source_type', 'source_id']);
            $table->foreign(['period_id', 'organization_id'])->references(['id', 'organization_id'])->on('periods')->restrictOnDelete();
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['counter_branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['posted_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign(['reversal_of_id', 'organization_id'])->references(['id', 'organization_id'])->on('journal_entries')->restrictOnDelete();
        });
        DB::statement('create unique index journal_entries_reversal_unique on journal_entries (reversal_of_id) where reversal_of_id is not null');
        DB::statement("alter table journal_entries add constraint journal_entries_values_check check (total_cents >= 0 and event <> '' and source_type <> '' and reversal_of_id is distinct from id)");

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('journal_entry_id')->index();
            $table->unsignedSmallInteger('position');
            $table->ulid('account_id');
            $table->ulid('branch_id');
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            // The AR / customer-deposit subledger key.
            $table->ulid('customer_account_id')->nullable();
            $table->ulid('stock_move_id')->nullable();
            $table->string('memo', 200)->default('');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['account_id', 'branch_id']);
            $table->index('customer_account_id');
            $table->foreign(['journal_entry_id', 'organization_id'])->references(['id', 'organization_id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['account_id', 'organization_id'])->references(['id', 'organization_id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign('stock_move_id')->references('id')->on('stock_moves')->restrictOnDelete();
        });
        // A zero-valued line is allowed (a move worth nothing is still posted, so "posted" means one thing).
        DB::statement('alter table journal_lines add constraint journal_lines_values_check check (debit_cents >= 0 and credit_cents >= 0 and not (debit_cents > 0 and credit_cents > 0))');
        DB::statement('create unique index journal_lines_stock_move_unique on journal_lines (stock_move_id) where stock_move_id is not null');

        Schema::create('account_export_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('account_id');
            $table->string('target', 16);
            // Xero imports by account code; QuickBooks Online by account name.
            $table->string('external_code', 64)->nullable();
            $table->string('external_name', 160)->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'account_id', 'target']);
            $table->foreign(['account_id', 'organization_id'])->references(['id', 'organization_id'])->on('accounts')->restrictOnDelete();
        });
        DB::statement("alter table account_export_mappings add constraint account_export_mappings_target_check check (target in ('xero', 'quickbooks'))");

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('accounting_target', 16)->default('none');
        });
        DB::statement("alter table organizations add constraint organizations_accounting_target_check check (accounting_target in ('none', 'xero', 'quickbooks'))");

        AppendOnly::protect('journal_entries');
        AppendOnly::protect('journal_lines');

        DB::unprepared(<<<'SQL'
            -- An entry balances, and has at least two lines. Re-reads the CURRENT rows at commit.
            create or replace function journal_entry_balances(target char(26)) returns void language plpgsql as $$
            declare
                debits bigint;
                credits bigint;
                lines integer;
            begin
                select coalesce(sum(debit_cents), 0), coalesce(sum(credit_cents), 0), count(*)
                    into debits, credits, lines from journal_lines where journal_entry_id = target;
                if lines < 2 then
                    raise exception 'journal entry % has % line(s); an entry has at least two', target, lines using errcode = 'check_violation';
                end if;
                if debits <> credits then
                    raise exception 'journal entry % does not balance: debits % credits %', target, debits, credits using errcode = 'check_violation';
                end if;
            end
            $$;

            create or replace function journal_lines_balance_check() returns trigger language plpgsql as $$
            begin
                perform journal_entry_balances(new.journal_entry_id);
                return null;
            end
            $$;
            create constraint trigger journal_lines_balance_check
                after insert on journal_lines deferrable initially deferred
                for each row execute function journal_lines_balance_check();

            create or replace function journal_entries_balance_check() returns trigger language plpgsql as $$
            begin
                perform journal_entry_balances(new.id);
                return null;
            end
            $$;
            create constraint trigger journal_entries_balance_check
                after insert on journal_entries deferrable initially deferred
                for each row execute function journal_entries_balance_check();

            -- Dated inside its period; a closed period takes nothing new.
            create or replace function journal_entries_period_open() returns trigger language plpgsql as $$
            declare
                period_row periods%rowtype;
            begin
                select * into period_row from periods where id = new.period_id;
                if new.entry_date < period_row.starts_on or new.entry_date > period_row.ends_on then
                    raise exception 'entry dated % is outside period %', new.entry_date, period_row.period_key using errcode = 'check_violation';
                end if;
                if period_row.status <> 'open' then
                    raise exception 'period % is closed; nothing is posted into it', period_row.period_key using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger journal_entries_period_open
                before insert on journal_entries
                for each row execute function journal_entries_period_open();

            -- A closed period is final; an open one is closed, once.
            create or replace function periods_keep_closed() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'periods are never deleted' using errcode = 'restrict_violation';
                end if;
                if old.status = 'closed' then
                    raise exception 'period % is closed; it is not edited again', old.period_key using errcode = 'restrict_violation';
                end if;
                if new.period_key is distinct from old.period_key
                    or new.starts_on is distinct from old.starts_on
                    or new.ends_on is distinct from old.ends_on then
                    raise exception 'a period keeps its dates' using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger periods_keep_closed
                before update or delete on periods
                for each row execute function periods_keep_closed();

            -- Once posted to, an account keeps what it is.
            create or replace function accounts_keep_posted() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    if old.is_system or exists (select 1 from journal_lines where account_id = old.id) then
                        raise exception 'account % is part of the books; deactivate it instead', old.code using errcode = 'restrict_violation';
                    end if;
                    return old;
                end if;
                if (new.code is distinct from old.code or new.type is distinct from old.type or new.normal_side is distinct from old.normal_side)
                    and exists (select 1 from journal_lines where account_id = old.id) then
                    raise exception 'account % has been posted to; its code, type and side are fixed', old.code using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger accounts_keep_posted
                before update or delete on accounts
                for each row execute function accounts_keep_posted();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists accounts_keep_posted on accounts;
            drop function if exists accounts_keep_posted();
            drop trigger if exists periods_keep_closed on periods;
            drop function if exists periods_keep_closed();
            drop trigger if exists journal_entries_period_open on journal_entries;
            drop function if exists journal_entries_period_open();
            drop trigger if exists journal_entries_balance_check on journal_entries;
            drop function if exists journal_entries_balance_check();
            drop trigger if exists journal_lines_balance_check on journal_lines;
            drop function if exists journal_lines_balance_check();
            drop function if exists journal_entry_balances(char);
            SQL);
        AppendOnly::unprotect('journal_lines');
        AppendOnly::unprotect('journal_entries');
        DB::statement('alter table organizations drop constraint organizations_accounting_target_check');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('accounting_target');
        });
        Schema::dropIfExists('account_export_mappings');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('periods');
        Schema::dropIfExists('posting_rules');
        Schema::dropIfExists('accounts');
    }
};
