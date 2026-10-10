<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Database\Cell;
use App\Domain\Ledger\AccountBalance;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\Reports\BalanceSheet;
use App\Domain\Ledger\Reports\ProfitAndLoss;
use App\Domain\Ledger\Reports\TrialBalance;
use App\Domain\Ledger\RuleKey;
use App\Domain\Ledger\Side;
use App\Domain\Shared\Calendar;
use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The read side of the ledger, always over the branches the caller works in
 * (the selected branch, or all of theirs). A journal line belongs to its
 * branch, so every report adds up its own branches' lines; a report over
 * every branch is the consolidated one. `scope` in each answer says which.
 *
 * @phpstan-type JournalFilters array{from?: string, to?: string, account_id?: string, event?: string, branch_id?: string, q?: string, source_id?: string}
 */
final class LedgerQueries
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly PostingRules $rules,
    ) {}

    /**
     * @return list<string>
     */
    public function branchIds(): array
    {
        return $this->tenancy->require()->branchFilter();
    }

    /**
     * @return array{branches: list<array{id: string, name: string}>, all_branches: bool}
     */
    public function scope(): array
    {
        $context = $this->tenancy->require();
        $ids = $this->branchIds();
        $branches = Branch::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);

        return [
            'branches' => array_values($branches->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])->all()),
            'all_branches' => ! $context->branchRestricted && $context->selectedBranchId === null,
        ];
    }

    /* ------------------------------------------------------------- chart */

    /**
     * The chart with each account's balance through $asOf over the caller's branches.
     *
     * @return list<array<string, mixed>>
     */
    public function chart(string $asOf): array
    {
        $balances = [];
        foreach ($this->balances(null, $asOf) as $balance) {
            $balances[$balance->accountId] = $balance->balanceCents();
        }
        $rules = $this->rules->map($this->tenancy->require()->organizationId());
        $ruleKeys = [];
        foreach ($rules as $key => $accountId) {
            $ruleKeys[$accountId][] = $key;
        }
        $used = JournalLine::query()->distinct()->pluck('account_id')->flip();

        return array_values(Account::query()->orderBy('code')->get()->map(fn (Account $account): array => [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'normal_side' => $account->normal_side->value,
            'is_active' => $account->is_active,
            'is_system' => $account->is_system,
            'description' => $account->description,
            'balance_cents' => $balances[$account->id] ?? 0,
            'rule_keys' => $ruleKeys[$account->id] ?? [],
            // Once posted to, the code, type and side are fixed; and the books may hold it in another branch.
            'has_postings' => $used->has($account->id),
        ])->all());
    }

    /* --------------------------------------------------------------- journal */

    /**
     * @param  JournalFilters  $filters
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    public function journalPage(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = JournalEntry::query()->visibleTo($this->tenancy->require())->with(['lines.account', 'branch']);
        if (isset($filters['from'])) {
            $query->where('entry_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('entry_date', '<=', $filters['to']);
        }
        if (isset($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (isset($filters['branch_id'])) {
            $query->where(fn (Builder $b) => $b->where('branch_id', $filters['branch_id'])->orWhere('counter_branch_id', $filters['branch_id']));
        }
        if (isset($filters['source_id'])) {
            $query->where('source_id', $filters['source_id']);
        }
        if (isset($filters['account_id'])) {
            $query->whereExists(fn (QueryBuilder $l) => $l->select(DB::raw('1'))->from('journal_lines as l')->whereColumn('l.journal_entry_id', 'journal_entries.id')->where('l.account_id', $filters['account_id']));
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%'.addcslashes(mb_strtolower((string) $filters['q']), '%_\\').'%';
            $query->where(fn (Builder $w) => $w->whereRaw('lower(number) like ?', [$like])->orWhereRaw('lower(reference) like ?', [$like])->orWhereRaw('lower(memo) like ?', [$like]));
        }

        return $query->orderByDesc('entry_date')->orderByDesc('number')->paginate($perPage);
    }

    public function entry(JournalEntry $entry): JournalEntry
    {
        return $entry->load(['lines.account', 'branch', 'reversalOf', 'reversedBy', 'period']);
    }

    /* -------------------------------------------------------------- reports */

    /**
     * @return array<string, mixed>
     */
    public function trialBalance(string $asOf): array
    {
        return ['as_of' => $asOf, 'scope' => $this->scope()] + TrialBalance::build($this->balances(null, $asOf));
    }

    /**
     * One account's lines over a range, each with the running balance on the
     * account's own side, after the balance brought forward.
     *
     * @return array<string, mixed>
     */
    public function generalLedger(Account $account, string $from, string $to, int $page, int $perPage): array
    {
        $side = $account->normal_side;
        $opening = $this->netDebit($account->id, $this->dayBefore($from));
        $sign = $side === Side::Debit ? 1 : -1;

        $lines = $this->lines()
            ->where('journal_lines.account_id', $account->id)
            ->whereBetween('e.entry_date', [$from, $to])
            ->selectRaw('journal_lines.id, journal_lines.debit_cents, journal_lines.credit_cents, journal_lines.branch_id, journal_lines.memo as line_memo, journal_lines.customer_account_id')
            ->selectRaw('e.id as entry_id, e.number, e.entry_date, e.event, e.reference, e.memo')
            ->selectRaw('sum(journal_lines.debit_cents - journal_lines.credit_cents) over (order by e.entry_date, e.number, journal_lines.position, journal_lines.id) as running_net')
            ->toBase();

        $window = DB::query()->fromSub($lines, 't');
        $total = (clone $window)->count();
        $rows = $window->orderBy('entry_date')->orderBy('number')->orderBy('id')->forPage($page, $perPage)->get();

        $totals = $this->lines()->where('journal_lines.account_id', $account->id)->whereBetween('e.entry_date', [$from, $to])
            ->selectRaw('coalesce(sum(journal_lines.debit_cents), 0) as debits, coalesce(sum(journal_lines.credit_cents), 0) as credits')->toBase()->first();
        $debits = Cell::int($totals->debits ?? null);
        $credits = Cell::int($totals->credits ?? null);

        $branchNames = Branch::query()->whereIn('id', $this->branchIds())->pluck('name', 'id');

        $out = [];
        foreach ($rows as $row) {
            $event = Cell::string($row->event);
            $branchId = Cell::string($row->branch_id);
            $out[] = [
                'id' => Cell::string($row->id),
                'entry_id' => Cell::string($row->entry_id),
                'number' => Cell::string($row->number),
                'entry_date' => Cell::date($row->entry_date),
                'event' => $event,
                'event_label' => LedgerEvent::tryFrom($event)?->label() ?? $event,
                'reference' => Cell::string($row->reference),
                'memo' => Cell::string($row->memo),
                'branch_id' => $branchId,
                'branch_name' => Cell::string($branchNames[$branchId] ?? null),
                'debit_cents' => Cell::int($row->debit_cents),
                'credit_cents' => Cell::int($row->credit_cents),
                'balance_cents' => $sign * ($opening + Cell::int($row->running_net)),
            ];
        }

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type->value, 'normal_side' => $side->value],
            'from' => $from,
            'to' => $to,
            'scope' => $this->scope(),
            'opening_balance_cents' => $sign * $opening,
            'total_debit_cents' => $debits,
            'total_credit_cents' => $credits,
            'closing_balance_cents' => $sign * ($opening + $debits - $credits),
            'lines' => $out,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    /**
     * Profit and loss over a range with a column per branch and the consolidated total.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(string $from, string $to): array
    {
        $branches = $this->scope()['branches'];
        $branchIds = array_map(fn (array $b): string => $b['id'], $branches);
        $costOfSales = [];
        $map = $this->rules->map($this->tenancy->require()->organizationId());
        foreach (RuleKey::cases() as $key) {
            if ($key->isCostOfSales() && isset($map[$key->value])) {
                $costOfSales[] = $map[$key->value];
            }
        }

        $balances = array_values(array_filter(
            $this->balances($from, $to, byBranch: true),
            fn (AccountBalance $b): bool => $b->type === AccountType::Revenue || $b->type === AccountType::Expense,
        ));

        return ['from' => $from, 'to' => $to, 'branches' => $branches, 'scope' => $this->scope()] + ProfitAndLoss::build($balances, $branchIds, $costOfSales);
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceSheet(string $asOf): array
    {
        return ['as_of' => $asOf, 'scope' => $this->scope()] + BalanceSheet::build($this->balances(null, $asOf));
    }

    /**
     * Sales and receipts by day, branch and payment method. Sales are the
     * revenue the invoices booked (net of discounts and VAT) and the VAT they
     * charged, voids netted off on the day they happened; receipts are the
     * money taken, by the method it came in.
     *
     * @return array<string, mixed>
     */
    public function dailySales(string $from, string $to): array
    {
        $invoiceEvents = [LedgerEvent::InvoiceIssued->value, LedgerEvent::InvoiceVoided->value];
        $paymentEvents = [LedgerEvent::PaymentReceived->value, LedgerEvent::PaymentVoided->value];
        $outputVat = $this->rules->accountId($this->tenancy->require()->organizationId(), RuleKey::OutputVat);

        $sales = $this->lines()
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->whereBetween('e.entry_date', [$from, $to])->whereIn('e.event', $invoiceEvents)
            ->where(fn (Builder $w) => $w->where('a.type', AccountType::Revenue->value)->orWhere('journal_lines.account_id', $outputVat))
            ->selectRaw('e.entry_date, journal_lines.branch_id')
            ->selectRaw("coalesce(sum(case when a.type = 'revenue' then journal_lines.credit_cents - journal_lines.debit_cents else 0 end), 0) as net_sales")
            ->selectRaw('coalesce(sum(case when journal_lines.account_id = ? then journal_lines.credit_cents - journal_lines.debit_cents else 0 end), 0) as vat', [$outputVat])
            ->groupBy('e.entry_date', 'journal_lines.branch_id')->toBase()->get();

        // The cash-side line of a payment entry is its asset line; the deposit it opens is a liability.
        $receipts = $this->lines()
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->whereBetween('e.entry_date', [$from, $to])->whereIn('e.event', $paymentEvents)->where('a.type', AccountType::Asset->value)
            ->selectRaw('e.entry_date, journal_lines.branch_id, e.payment_method')
            ->selectRaw('coalesce(sum(journal_lines.debit_cents - journal_lines.credit_cents), 0) as received')
            ->groupBy('e.entry_date', 'journal_lines.branch_id', 'e.payment_method')->toBase()->get();

        $names = Branch::query()->whereIn('id', $this->branchIds())->pluck('name', 'id');

        /** @var array<string, array{date: string, branch_id: string, net: int, vat: int}> $invoiced */
        $invoiced = [];
        foreach ($sales as $line) {
            $date = Cell::date($line->entry_date);
            $branchId = Cell::string($line->branch_id);
            $invoiced["{$date}|{$branchId}"] = ['date' => $date, 'branch_id' => $branchId, 'net' => Cell::int($line->net_sales), 'vat' => Cell::int($line->vat)];
        }
        /** @var array<string, array<string, int>> $byMethod */
        $byMethod = [];
        /** @var array<string, array{date: string, branch_id: string}> $received */
        $received = [];
        foreach ($receipts as $line) {
            $date = Cell::date($line->entry_date);
            $branchId = Cell::string($line->branch_id);
            $key = "{$date}|{$branchId}";
            $method = Cell::string($line->payment_method) ?: 'other';
            $received[$key] = ['date' => $date, 'branch_id' => $branchId];
            $byMethod[$key][$method] = ($byMethod[$key][$method] ?? 0) + Cell::int($line->received);
        }

        $rows = [];
        foreach (array_unique([...array_keys($invoiced), ...array_keys($received)]) as $key) {
            $day = $invoiced[$key] ?? ['date' => $received[$key]['date'] ?? '', 'branch_id' => $received[$key]['branch_id'] ?? '', 'net' => 0, 'vat' => 0];
            $methods = $byMethod[$key] ?? [];
            ksort($methods);
            $money = array_sum($methods);
            if ($day['net'] === 0 && $day['vat'] === 0 && $money === 0) {
                continue;
            }
            $rows[] = [
                'date' => $day['date'],
                'branch_id' => $day['branch_id'],
                'branch_name' => Cell::string($names[$day['branch_id']] ?? null),
                'net_sales_cents' => $day['net'],
                'vat_cents' => $day['vat'],
                'invoiced_cents' => $day['net'] + $day['vat'],
                'received_cents' => $money,
                'receipts' => array_map(fn (string $method, int $cents): array => ['method' => $method, 'received_cents' => $cents], array_keys($methods), array_values($methods)),
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['branch_name']] <=> [$b['date'], $b['branch_name']]);

        return [
            'from' => $from,
            'to' => $to,
            'scope' => $this->scope(),
            'days' => $rows,
            'totals' => [
                'net_sales_cents' => array_sum(array_column($rows, 'net_sales_cents')),
                'vat_cents' => array_sum(array_column($rows, 'vat_cents')),
                'invoiced_cents' => array_sum(array_column($rows, 'invoiced_cents')),
                'received_cents' => array_sum(array_column($rows, 'received_cents')),
            ],
        ];
    }

    /**
     * The accounting periods, newest first, with what each holds and whether it can be closed now.
     *
     * @return list<Period>
     */
    public function periods(): array
    {
        return array_values(Period::query()->orderByDesc('period_key')->get()->all());
    }

    /* ------------------------------------------------------------- balances */

    /**
     * Debits and credits per account (and per branch if asked), over the
     * caller's branches, for entries dated from `$from` (or the beginning)
     * to `$to`. Every account is returned, active or not, even with nothing posted.
     *
     * @return list<AccountBalance>
     */
    public function balances(?string $from, string $to, bool $byBranch = false): array
    {
        $query = $this->lines()->where('e.entry_date', '<=', $to);
        if ($from !== null) {
            $query->where('e.entry_date', '>=', $from);
        }
        $select = ['journal_lines.account_id'];
        $group = ['journal_lines.account_id'];
        if ($byBranch) {
            $select[] = 'journal_lines.branch_id';
            $group[] = 'journal_lines.branch_id';
        }
        $rows = $query->select($select)
            ->selectRaw('coalesce(sum(journal_lines.debit_cents), 0) as debits, coalesce(sum(journal_lines.credit_cents), 0) as credits')
            ->groupBy($group)->toBase()->get();

        $accounts = Account::query()->get()->keyBy('id');
        $balances = [];
        $seen = [];
        foreach ($rows as $row) {
            $account = $accounts->get(Cell::string($row->account_id));
            if ($account === null) {
                continue;
            }
            $seen[$account->id] = true;
            $balances[] = new AccountBalance($account->id, $account->code, $account->name, $account->type, $account->normal_side, Cell::int($row->debits), Cell::int($row->credits), $byBranch ? Cell::string($row->branch_id) : null);
        }
        if (! $byBranch) {
            foreach ($accounts as $account) {
                if (! isset($seen[$account->id])) {
                    $balances[] = new AccountBalance($account->id, $account->code, $account->name, $account->type, $account->normal_side, 0, 0);
                }
            }
        }

        return $balances;
    }

    /** Debits less credits on one account over the caller's branches, through a date. */
    private function netDebit(string $accountId, string $through): int
    {
        $row = $this->lines()->where('journal_lines.account_id', $accountId)->where('e.entry_date', '<=', $through)
            ->selectRaw('coalesce(sum(journal_lines.debit_cents), 0) as debits, coalesce(sum(journal_lines.credit_cents), 0) as credits')->toBase()->first();

        return Cell::int($row->debits ?? null) - Cell::int($row->credits ?? null);
    }

    /**
     * Journal lines joined to their entries, inside the caller's branches.
     *
     * @return Builder<JournalLine>
     */
    private function lines(): Builder
    {
        return JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.branch_id', $this->branchIds());
    }

    private function dayBefore(string $date): string
    {
        return Calendar::toDate(Calendar::addDays(Calendar::parseDate($date), -1));
    }
}
