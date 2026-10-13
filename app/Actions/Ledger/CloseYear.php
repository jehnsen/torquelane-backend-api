<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Actions\Audit\AuditTrail;
use App\Database\Cell;
use App\Domain\Ledger\AccountBalance;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\Periods;
use App\Domain\Ledger\PeriodStatus;
use App\Domain\Ledger\Postings\SubledgerPostings;
use App\Domain\Ledger\Postings\YearCloseFacts;
use App\Domain\Ledger\Side;
use App\Domain\Shared\Calendar;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The year-end close. The fiscal year is the calendar year. Closing it posts
 * ONE entry that turns every revenue and expense account back to zero, branch
 * by branch, and moves the difference (the year's profit or loss) to Retained
 * Earnings; then December is closed like any other month, in the same
 * transaction. Nothing of the year is rewritten: the entry is an ordinary
 * journal entry (`year_closed`), and the profit and loss report leaves it out
 * so a closed year still reads as it was earned.
 *
 * Allowed once the year is over, every earlier year with entries is closed,
 * and December's own close would pass (the months before it closed, the
 * checklist clean). A year is closed once; there is no reopening.
 *
 * If December was already closed by the monthly close before this existed,
 * the entry cannot be dated inside it (a closed period takes nothing): it is
 * dated the first day of the first month still open, and its memo says so.
 * Retained Earnings and every later report come out the same.
 */
final class CloseYear
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly Ledger $ledger,
        private readonly LedgerPostings $postings,
        private readonly ClosePeriod $periods,
        private readonly PostingRules $rules,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Where a year stands: what closing it would move, and what is in the way.
     *
     * @return array{year: int, status: string, ended: bool, revenue_cents: int, expense_cents: int, net_income_cents: int, december_status: string, can_close: bool, blocked_by: string|null, closed_at: string|null, closed_by_name: string|null}
     */
    public function preview(int $year): array
    {
        $closed = FiscalYear::query()->where('year', $year)->first();
        $balances = $this->balances($year);
        $revenue = 0;
        $expense = 0;
        foreach ($balances as $balance) {
            if ($balance->type === AccountType::Revenue) {
                $revenue -= $balance->netDebitCents();
            } else {
                $expense += $balance->netDebitCents();
            }
        }
        $ended = Calendar::toDate(CarbonImmutable::now()) > "{$year}-12-31";
        $earlier = $this->earlierOpenYear($year);
        $december = Period::query()->where('period_key', "{$year}-12")->first();

        $blocked = match (true) {
            $closed !== null => "{$year} is already closed.",
            ! $ended => "{$year} is not over yet.",
            $earlier !== null => "{$earlier} has entries and is still open; close the years in order.",
            default => null,
        };
        if ($blocked === null && ($december === null || $december->isOpen())) {
            $month = $this->periods->preview("{$year}-12");
            $blocked = $month['can_close'] ? null : 'December cannot be closed yet: '.($month['blocked_by'] ?? 'the checklist has failures.');
        }

        return [
            'year' => $year,
            'status' => $closed === null ? 'open' : 'closed',
            'ended' => $ended,
            'revenue_cents' => $revenue,
            'expense_cents' => $expense,
            'net_income_cents' => $closed === null ? $revenue - $expense : $closed->net_income_cents,
            'december_status' => $december === null || $december->isOpen() ? PeriodStatus::Open->value : PeriodStatus::Closed->value,
            'can_close' => $blocked === null,
            'blocked_by' => $blocked,
            'closed_at' => $closed?->closed_at->toIso8601ZuluString(),
            'closed_by_name' => $closed?->closed_by_name,
        ];
    }

    public function close(int $year): FiscalYear
    {
        $context = $this->tenancy->require();
        $organizationId = $context->organizationId();

        return DB::transaction(function () use ($year, $organizationId, $context): FiscalYear {
            // Serialises closes of the same year; the unique index is the backstop.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["fiscal-year:{$organizationId}:{$year}"]);
            if (FiscalYear::query()->where('year', $year)->exists()) {
                throw new InvalidTransitionException("{$year} is already closed.");
            }
            if (Calendar::toDate(CarbonImmutable::now()) <= "{$year}-12-31") {
                throw new InvalidTransitionException("{$year} is not over yet.");
            }
            if (($earlier = $this->earlierOpenYear($year)) !== null) {
                throw new InvalidTransitionException("{$earlier} has entries and is still open; close the years in order.");
            }

            // December, held against postings in flight (shared lock) if it is still open.
            $december = Period::query()->where('period_key', "{$year}-12")->first();
            $decemberOpen = $december === null || $december->isOpen();
            $entryDate = $decemberOpen ? "{$year}-12-31" : $this->firstOpenDay($organizationId, $year);
            $this->ledger->openPeriod($organizationId, $entryDate);

            $balances = $this->balances($year);
            $facts = new YearCloseFacts('', $year, $this->filingBranch($balances, $organizationId), $balances, $entryDate);
            $actor = User::query()->findOrFail($context->userId);

            // A closed year is final (trigger): inserted once, figure and all.
            $fiscalYear = new FiscalYear;
            $fiscalYear->forceFill([
                'year' => $year,
                'net_income_cents' => SubledgerPostings::netIncome($facts),
                'closed_at' => CarbonImmutable::now(),
                'closed_by' => $actor->id,
                'closed_by_name' => $actor->name,
            ])->save();

            if (array_filter($balances, fn (AccountBalance $balance): bool => $balance->netDebitCents() !== 0) !== []) {
                $this->rules->map($organizationId);
                $this->postings->yearClosed($fiscalYear, $facts->branchId, $balances, $entryDate);
            }
            if ($decemberOpen) {
                // The month's own rules apply: earlier months closed, the checklist clean.
                $this->periods->close("{$year}-12");
            }
            $this->audit->record($fiscalYear, 'closed', null, AuditTrail::snapshot($fiscalYear));

            return $fiscalYear;
        });
    }

    /**
     * The year's revenue and expense balances per account and branch, over
     * EVERY branch of the organization, before any closing entry.
     *
     * @return list<AccountBalance>
     */
    private function balances(int $year): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.organization_id', $this->tenancy->require()->organizationId())
            ->whereBetween('e.entry_date', ["{$year}-01-01", "{$year}-12-31"])
            ->where('e.event', '<>', LedgerEvent::YearClosed->value)
            ->whereIn('a.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_side', 'l.branch_id')
            ->orderBy('a.code')->orderBy('l.branch_id')
            ->selectRaw('a.id, a.code, a.name, a.type, a.normal_side, l.branch_id, coalesce(sum(l.debit_cents), 0) as debits, coalesce(sum(l.credit_cents), 0) as credits')
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $balances[] = new AccountBalance(
                Cell::string($row->id),
                Cell::string($row->code),
                Cell::string($row->name),
                AccountType::from(Cell::string($row->type)),
                Side::from(Cell::string($row->normal_side)),
                Cell::int($row->debits),
                Cell::int($row->credits),
                Cell::string($row->branch_id),
            );
        }

        return $balances;
    }

    /** The earliest year before this one that has entries and has not been closed. */
    private function earlierOpenYear(int $year): ?int
    {
        $first = DB::table('journal_entries')->where('organization_id', $this->tenancy->require()->organizationId())->min('entry_date');
        if ($first === null) {
            return null;
        }
        $closed = array_map(fn (mixed $y): int => Cell::int($y), FiscalYear::query()->pluck('year')->all());
        for ($candidate = (int) substr(Cell::date($first), 0, 4); $candidate < $year; $candidate++) {
            if (! in_array($candidate, $closed, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The first day of the first month after the year that is not closed. */
    private function firstOpenDay(string $organizationId, int $year): string
    {
        $today = Calendar::toDate(CarbonImmutable::now());
        $closed = Period::query()->where('status', PeriodStatus::Closed->value)->pluck('period_key')->all();
        for ($month = Calendar::parseDate(($year + 1).'-01-01'); Calendar::toDate($month) <= $today; $month = Calendar::addMonths($month, 1)) {
            if (! in_array(Periods::keyOf(Calendar::toDate($month)), $closed, true)) {
                return Calendar::toDate($month);
            }
        }

        throw new ConflictException("Every month since {$year} is closed; there is no open day to date the closing entry.", ['reason' => 'period_closed']);
    }

    /**
     * The branch the entry is filed under: the first with anything to close, else the organization's first.
     *
     * @param  list<AccountBalance>  $balances
     */
    private function filingBranch(array $balances, string $organizationId): string
    {
        foreach ($balances as $balance) {
            if ($balance->branchId !== null && $balance->netDebitCents() !== 0) {
                return $balance->branchId;
            }
        }

        return Cell::string(Branch::query()->where('organization_id', $organizationId)->orderBy('id')->value('id'));
    }
}
