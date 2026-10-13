<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Actions\Audit\AuditTrail;
use App\Database\Cell;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\BankStatement;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\WebFormat;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\JournalLine;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation: agreeing a cash-side account (the bank, a wallet, the
 * card acquirer, the till) with the statement of whoever holds the money.
 *
 * open: one statement at a time per account (its closing date and balance),
 * dated after the last one completed. tick: the journal lines of that account
 * the statement shows; a line clears once, ever. complete: only when the
 * statement balance equals everything that has cleared (`BankStatement`);
 * then it is final. What is in the books and not on the statement stays
 * outstanding and is carried to the next one. An open reconciliation may be
 * discarded.
 *
 * Whole-organization: an account's lines are every branch's, so the policy
 * keeps this to a session that is not branch-limited. A difference is put
 * right in the books first (a journal voucher for a bank charge, a refund or
 * payment that was never recorded), never by forcing the statement.
 */
final class ManageBankReconciliations
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly PostingRules $rules,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{statement_date: string, statement_balance_cents: int, notes?: string|null}  $data  validated
     */
    public function open(Account $account, array $data): BankReconciliation
    {
        return DB::transaction(function () use ($account, $data): BankReconciliation {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            if ($account->type !== AccountType::Asset || in_array($account->id, $this->rules->controlAccountIds($account->organization_id), true)) {
                throw ValidationException::withMessages(['account_id' => "{$account->name} is not a cash, bank, wallet or clearing account."]);
            }
            if ($data['statement_date'] > Calendar::toDate(CarbonImmutable::now())) {
                throw ValidationException::withMessages(['statement_date' => 'A statement is never dated in the future.']);
            }
            if (BankReconciliation::query()->where('account_id', $account->id)->where('status', BankReconciliation::OPEN)->exists()) {
                throw new ConflictException("{$account->name} already has a reconciliation open; complete or discard it first.", ['reason' => 'reconciliation_open']);
            }
            $last = BankReconciliation::query()->where('account_id', $account->id)->where('status', BankReconciliation::COMPLETED)->max('statement_date');
            if ($last !== null && $data['statement_date'] <= Cell::date($last)) {
                throw ValidationException::withMessages(['statement_date' => sprintf('%s is already reconciled to %s; the next statement closes after that.', $account->name, Cell::date($last))]);
            }

            $actor = $this->actor();
            $reconciliation = new BankReconciliation;
            $reconciliation->forceFill([
                'account_id' => $account->id,
                'statement_date' => $data['statement_date'],
                'statement_balance_cents' => $data['statement_balance_cents'],
                'status' => BankReconciliation::OPEN,
                'notes' => trim((string) ($data['notes'] ?? '')),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();
            $this->audit->record($reconciliation, 'opened', null, AuditTrail::snapshot($reconciliation));

            return $reconciliation->setRelation('account', $account);
        });
    }

    /**
     * Tick and untick lines against the statement.
     *
     * @param  list<string>  $clear  journal line ids the statement shows
     * @param  list<string>  $unclear  journal line ids ticked by mistake
     */
    public function tick(BankReconciliation $reconciliation, array $clear, array $unclear): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $clear, $unclear): BankReconciliation {
            $locked = $this->lockOpen($reconciliation);
            if ($unclear !== []) {
                BankReconciliationLine::query()->where('bank_reconciliation_id', $locked->id)->whereIn('journal_line_id', $unclear)->delete();
            }

            $clear = array_values(array_unique($clear));
            if ($clear !== []) {
                $lines = JournalLine::query()
                    ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
                    ->whereIn('journal_lines.id', $clear)
                    ->get(['journal_lines.id', 'journal_lines.account_id', 'e.entry_date'])
                    ->keyBy('id');
                $taken = BankReconciliationLine::query()->whereIn('journal_line_id', $clear)->pluck('bank_reconciliation_id', 'journal_line_id');
                foreach ($clear as $index => $lineId) {
                    $line = $lines->get($lineId);
                    $field = "clear.{$index}";
                    if (! $line instanceof JournalLine || $line->account_id !== $locked->account_id) {
                        throw ValidationException::withMessages([$field => 'That journal line is not on the account being reconciled.']);
                    }
                    if (Cell::date($line->getAttribute('entry_date')) > $locked->statement_date->toDateString()) {
                        throw ValidationException::withMessages([$field => 'That line is dated after the statement closes.']);
                    }
                    $owner = $taken->get($lineId);
                    if ($owner === $locked->id) {
                        continue;
                    }
                    if ($owner !== null) {
                        throw ValidationException::withMessages([$field => 'That line has already cleared on an earlier statement.']);
                    }
                    (new BankReconciliationLine)->forceFill(['bank_reconciliation_id' => $locked->id, 'journal_line_id' => $lineId])->save();
                }
            }

            return $locked;
        });
    }

    public function complete(BankReconciliation $reconciliation): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation): BankReconciliation {
            $locked = $this->lockOpen($reconciliation);
            $statement = $this->statement($locked);
            if (! $statement->agrees()) {
                throw new ConflictException(
                    sprintf('The statement says %s and the cleared lines come to %s: %s apart. Tick what the statement shows, or record what the books are missing.', WebFormat::pesos($statement->statementBalanceCents), WebFormat::pesos($statement->clearedCents()), WebFormat::pesos(abs($statement->differenceCents()))),
                    ['reason' => 'statement_does_not_agree', 'difference_cents' => $statement->differenceCents()],
                );
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill([
                'status' => BankReconciliation::COMPLETED,
                'cleared_balance_cents' => $statement->clearedCents(),
                'book_balance_cents' => $statement->bookBalanceCents(),
                'completed_at' => CarbonImmutable::now(),
                'completed_by_name' => $this->actor()->name,
            ])->save();
            $this->audit->record($locked, 'completed', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    public function discard(BankReconciliation $reconciliation): void
    {
        DB::transaction(function () use ($reconciliation): void {
            $locked = $this->lockOpen($reconciliation);
            $before = AuditTrail::snapshot($locked);
            $locked->delete();
            $this->audit->record($locked, 'discarded', $before, null);
        });
    }

    /** Where the statement stands against the books right now (for a completed one: as it stood). */
    public function statement(BankReconciliation $reconciliation): BankStatement
    {
        $net = fn (Builder $query): int => Cell::int($query->selectRaw('coalesce(sum(l.debit_cents - l.credit_cents), 0) as net')->value('net'));
        $lines = fn (): Builder => DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.organization_id', $reconciliation->organization_id)
            ->where('l.account_id', $reconciliation->account_id);

        $ticked = $net($lines()->join('bank_reconciliation_lines as c', 'c.journal_line_id', '=', 'l.id')->where('c.bank_reconciliation_id', $reconciliation->id));
        // Earlier statements of this account: what the bank had already cleared before this one.
        $before = $net($lines()
            ->join('bank_reconciliation_lines as c', 'c.journal_line_id', '=', 'l.id')
            ->join('bank_reconciliations as r', 'r.id', '=', 'c.bank_reconciliation_id')
            ->where('r.status', BankReconciliation::COMPLETED)
            ->where('r.statement_date', '<', $reconciliation->statement_date->toDateString()));
        $outstanding = $net($lines()
            ->where('e.entry_date', '<=', $reconciliation->statement_date->toDateString())
            ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('bank_reconciliation_lines as c')
                ->join('bank_reconciliations as r', 'r.id', '=', 'c.bank_reconciliation_id')
                ->whereColumn('c.journal_line_id', 'l.id')
                ->where('r.statement_date', '<=', $reconciliation->statement_date->toDateString())));

        return new BankStatement($reconciliation->statement_balance_cents, $before, $ticked, $outstanding);
    }

    /**
     * The lines the statement could show: everything on the account dated up
     * to the statement that no earlier statement cleared, oldest first, each
     * marked cleared or not on this one.
     *
     * @return list<array{journal_line_id: string, entry_id: string, entry_number: string, entry_date: string, event: string, event_label: string, reference: string, memo: string, branch_id: string, debit_cents: int, credit_cents: int, cleared: bool}>
     */
    public function lines(BankReconciliation $reconciliation): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('bank_reconciliation_lines as c', 'c.journal_line_id', '=', 'l.id')
            ->where('l.organization_id', $reconciliation->organization_id)
            ->where('l.account_id', $reconciliation->account_id)
            ->where('e.entry_date', '<=', $reconciliation->statement_date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('c.id')->orWhere('c.bank_reconciliation_id', $reconciliation->id))
            ->orderBy('e.entry_date')->orderBy('e.number')->orderBy('l.position')
            ->get(['l.id', 'l.branch_id', 'l.debit_cents', 'l.credit_cents', 'l.memo', 'e.id as entry_id', 'e.number', 'e.entry_date', 'e.event', 'e.reference', 'c.id as cleared_id']);

        $lines = [];
        foreach ($rows as $row) {
            $event = Cell::string($row->event);
            $lines[] = [
                'journal_line_id' => Cell::string($row->id),
                'entry_id' => Cell::string($row->entry_id),
                'entry_number' => Cell::string($row->number),
                'entry_date' => Cell::date($row->entry_date),
                'event' => $event,
                'event_label' => LedgerEvent::tryFrom($event)?->label() ?? $event,
                'reference' => Cell::string($row->reference),
                'memo' => Cell::string($row->memo),
                'branch_id' => Cell::string($row->branch_id),
                'debit_cents' => Cell::int($row->debit_cents),
                'credit_cents' => Cell::int($row->credit_cents),
                'cleared' => $row->cleared_id !== null,
            ];
        }

        return $lines;
    }

    private function lockOpen(BankReconciliation $reconciliation): BankReconciliation
    {
        $locked = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
        if (! $locked->isOpen()) {
            throw new InvalidTransitionException('That reconciliation is completed; it is final.');
        }

        return $locked;
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->tenancy->require()->userId);
    }
}
