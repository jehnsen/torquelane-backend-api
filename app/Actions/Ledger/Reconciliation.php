<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Database\Cell;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\NegativeStockPolicy;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockState;
use App\Domain\Ledger\CloseChecklist;
use App\Domain\Ledger\RuleKey;
use App\Domain\Shared\BusinessCalendar;
use App\Domain\Shared\Calendar;
use App\Models\StockMove;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Do the books agree with the things they summarise? Receivables against the
 * open invoices, Inventory against the stock room's valuation, Customer
 * Deposits against unapplied payments, and the trial balance against itself;
 * and is any invoice, payment or stock move still waiting to be posted.
 *
 * Whole-organization, not the caller's branches: a reconciliation that depends
 * on who asks is not a reconciliation. Every figure is "as of" a business date
 * (end of that Manila day), so a month can be checked after it has closed.
 */
final class Reconciliation
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly PostingRules $rules,
    ) {}

    /**
     * @return list<array{key: string, label: string, passed: bool, expected_cents: int|null, actual_cents: int|null, difference_cents: int|null, detail: string}>
     */
    public function checklist(string $asOf): array
    {
        $totals = $this->trialBalanceTotals($asOf);

        return CloseChecklist::evaluate(
            $this->unposted($asOf),
            $this->receivablesSubledger($asOf),
            $this->accountBalance(RuleKey::Receivables, $asOf),
            $this->inventoryValuation($asOf),
            $this->accountBalance(RuleKey::Inventory, $asOf),
            $this->unappliedCredit($asOf),
            $this->accountBalance(RuleKey::CustomerDeposits, $asOf),
            $totals['debits'],
            $totals['credits'],
        );
    }

    /**
     * Sources dated on or before $upTo that have no entry: the books are behind.
     *
     * @return array<string, int>
     */
    public function unposted(string $upTo): array
    {
        $org = $this->organizationId();
        $next = $this->endOfDay($upTo);

        $entryFor = fn (Builder $query, string $table, string $event): Builder => $query->whereNotExists(fn (Builder $e) => $e
            ->select(DB::raw('1'))->from('journal_entries as e')
            ->whereColumn('e.source_id', "{$table}.id")->where('e.event', $event)->where('e.organization_id', $org));

        $invoices = fn (): Builder => DB::table('invoices')->where('invoices.organization_id', $org);
        $payments = fn (): Builder => DB::table('payments')->where('payments.organization_id', $org);
        $allocations = fn (): Builder => DB::table('payment_allocations')->where('payment_allocations.organization_id', $org);

        return [
            'invoices' => $entryFor($invoices()->where('invoices.status', '<>', 'draft')->where('invoices.issue_date', '<=', $upTo), 'invoices', 'invoice_issued')->count(),
            'invoice voids' => $entryFor($invoices()->where('invoices.status', 'void')->where('invoices.voided_at', '<', $next), 'invoices', 'invoice_voided')->count(),
            'payments' => $entryFor($payments()->where('payments.received_on', '<=', $upTo), 'payments', 'payment_received')->count(),
            'payment voids' => $entryFor($payments()->where('payments.status', 'void')->where('payments.voided_at', '<', $next), 'payments', 'payment_voided')->count(),
            'credit applications' => $entryFor($allocations()->where('payment_allocations.allocated_on', '<=', $upTo), 'payment_allocations', 'credit_applied')->count(),
            'credit reversals' => $entryFor(
                $allocations()->join('payments as p', 'p.id', '=', 'payment_allocations.payment_id')->where('p.status', 'void')->where('p.voided_at', '<', $next),
                'payment_allocations',
                'credit_reversed',
            )->count(),
            'stock moves' => DB::table('stock_moves')->where('stock_moves.organization_id', $org)->where('stock_moves.occurred_at', '<', $next)
                ->whereNotExists(fn (Builder $l) => $l->select(DB::raw('1'))->from('journal_lines as l')->whereColumn('l.stock_move_id', 'stock_moves.id'))->count(),
        ];
    }

    /** What customers owe on issued, unvoided invoices, less what had been applied to them, as of the date. */
    public function receivablesSubledger(string $asOf): int
    {
        $org = $this->organizationId();
        $next = $this->endOfDay($asOf);

        $invoiced = (int) DB::table('invoices')
            ->where('organization_id', $org)->where('status', '<>', 'draft')->where('issue_date', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('voided_at')->orWhere('voided_at', '>=', $next))
            ->sum('total_due_cents');

        $applied = (int) DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->join('invoices as i', 'i.id', '=', 'a.invoice_id')
            ->where('a.organization_id', $org)->where('a.allocated_on', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('p.voided_at')->orWhere('p.voided_at', '>=', $next))
            ->where('i.status', '<>', 'draft')->where('i.issue_date', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('i.voided_at')->orWhere('i.voided_at', '>=', $next))
            ->sum('a.amount_cents');

        return $invoiced - $applied;
    }

    /** Money received and not yet applied to an invoice, as of the date. */
    public function unappliedCredit(string $asOf): int
    {
        $org = $this->organizationId();
        $next = $this->endOfDay($asOf);

        $received = (int) DB::table('payments')
            ->where('organization_id', $org)->where('received_on', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('voided_at')->orWhere('voided_at', '>=', $next))
            ->sum('amount_cents');

        $applied = (int) DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.organization_id', $org)->where('a.allocated_on', '<=', $asOf)->where('p.received_on', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('p.voided_at')->orWhere('p.voided_at', '>=', $next))
            ->sum('a.amount_cents');

        return $received - $applied;
    }

    /**
     * The stock room at average cost as of the date: each balance's on hand ×
     * average, rounded to a centavo, summed. Today it is read straight off the
     * balances; for an earlier date the moves are replayed through the same
     * ledger maths.
     */
    public function inventoryValuation(string $asOf): int
    {
        $org = $this->organizationId();
        $next = $this->endOfDay($asOf);

        $latest = StockMove::query()->withoutGlobalScopes()->where('organization_id', $org)->where('occurred_at', '>=', $next)->exists();
        if (! $latest) {
            $total = 0;
            foreach (DB::table('stock_balances')->where('organization_id', $org)->get(['on_hand', 'avg_cost_cents']) as $row) {
                $total += StockLedger::valuation(new StockState(BigDecimal::of(Cell::string($row->on_hand)), Cell::int($row->avg_cost_cents)));
            }

            return $total;
        }

        $total = 0;
        $state = null;
        $group = null;
        $rows = DB::table('stock_moves')->where('organization_id', $org)->where('occurred_at', '<', $next)
            ->orderBy('location_id')->orderBy('item_id')->orderBy('id')
            ->get(['location_id', 'item_id', 'quantity', 'unit_cost_cents', 'move_type']);
        foreach ($rows as $row) {
            $key = Cell::string($row->location_id).'|'.Cell::string($row->item_id);
            if ($key !== $group) {
                $total += $state === null ? 0 : StockLedger::valuation($state);
                $state = new StockState(BigDecimal::zero(), 0);
                $group = $key;
            }
            $move = new MoveRequest(MoveType::from(Cell::string($row->move_type)), Cell::string($row->quantity), Cell::int($row->unit_cost_cents));
            $state = StockLedger::applyMove($state, $move, NegativeStockPolicy::AllowAndFlag)->after;
        }

        return $total + ($state === null ? 0 : StockLedger::valuation($state));
    }

    /** An account's balance on its own normal side, through the date, over every branch. */
    public function accountBalance(RuleKey $key, string $asOf): int
    {
        $org = $this->organizationId();
        $accountId = $this->rules->accountId($org, $key);
        $row = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.organization_id', $org)->where('l.account_id', $accountId)->where('e.entry_date', '<=', $asOf)
            ->selectRaw('coalesce(sum(l.debit_cents), 0) as debits, coalesce(sum(l.credit_cents), 0) as credits, max(a.normal_side) as side')
            ->first();

        $net = Cell::int($row->debits ?? null) - Cell::int($row->credits ?? null);
        $side = Cell::string($row->side ?? null) ?: $key->accountType()->defaultSide()->value;

        return $side === 'debit' ? $net : -$net;
    }

    /**
     * @return array{debits: int, credits: int}
     */
    public function trialBalanceTotals(string $asOf): array
    {
        $row = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.organization_id', $this->organizationId())->where('e.entry_date', '<=', $asOf)
            ->selectRaw('coalesce(sum(l.debit_cents), 0) as debits, coalesce(sum(l.credit_cents), 0) as credits')
            ->first();

        return ['debits' => Cell::int($row->debits ?? null), 'credits' => Cell::int($row->credits ?? null)];
    }

    private function organizationId(): string
    {
        return $this->tenancy->require()->organizationId();
    }

    /** The first instant after the Manila day, in UTC: what "by the end of that day" means to a timestamp. */
    private function endOfDay(string $date): DateTimeInterface
    {
        return BusinessCalendar::startOfDayUtc(Calendar::toDate(Calendar::addDays(Calendar::parseDate($date), 1)));
    }
}
