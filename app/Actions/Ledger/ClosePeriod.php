<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Actions\Audit\AuditTrail;
use App\Domain\Ledger\CloseChecklist;
use App\Domain\Ledger\Periods;
use App\Domain\Ledger\PeriodStatus;
use App\Domain\Shared\Calendar;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\Period;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Closing a month (R7: a closed period is final). Allowed only once the month
 * is over and every earlier month with activity is closed, and only if the
 * checklist passes: nothing unposted, receivables = AR, stock valuation =
 * Inventory, unapplied credit = Customer Deposits, trial balance balanced.
 *
 * The period row is locked FOR UPDATE; every posting holds it FOR SHARE until
 * it commits, so a close waits for postings in flight and then no new one
 * gets in. The checklist is evaluated AFTER the lock and kept on the period.
 */
final class ClosePeriod
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly Ledger $ledger,
        private readonly Reconciliation $reconciliation,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * The checklist as it stands now for a month (read-only; creates nothing).
     *
     * @return array{period_key: string, label: string, starts_on: string, ends_on: string, status: string, ended: bool, checklist: list<array<string, mixed>>, can_close: bool, blocked_by: string|null}
     */
    public function preview(string $periodKey): array
    {
        $bounds = Periods::bounds($periodKey);
        $period = Period::query()->where('period_key', $periodKey)->first();
        $ended = Calendar::toDate(CarbonImmutable::now()) > $bounds['ends_on'];
        $checklist = $this->reconciliation->checklist($bounds['ends_on']);
        $earlier = $this->earlierOpen($periodKey);
        $closed = $period !== null && ! $period->isOpen();

        $blocked = match (true) {
            $closed => 'This month is already closed.',
            ! $ended => 'The month is not over yet.',
            $earlier !== null => Periods::label($earlier).' is still open; close the months in order.',
            ! CloseChecklist::passes($checklist) => 'The checklist has failures.',
            default => null,
        };

        return [
            'period_key' => $periodKey,
            'label' => Periods::label($periodKey),
            'starts_on' => $bounds['starts_on'],
            'ends_on' => $bounds['ends_on'],
            'status' => $closed ? PeriodStatus::Closed->value : PeriodStatus::Open->value,
            'ended' => $ended,
            'checklist' => $checklist,
            'can_close' => $blocked === null,
            'blocked_by' => $blocked,
        ];
    }

    public function close(string $periodKey): Period
    {
        $bounds = Periods::bounds($periodKey);
        $organizationId = $this->tenancy->require()->organizationId();

        return DB::transaction(function () use ($periodKey, $bounds, $organizationId): Period {
            // Creates the row if nothing was ever posted that month, then locks it against postings.
            $this->ledger->openPeriod($organizationId, $bounds['starts_on']);
            $period = Period::query()->where('period_key', $periodKey)->lockForUpdate()->firstOrFail();
            if (! $period->isOpen()) {
                throw new InvalidTransitionException(Periods::label($periodKey).' is already closed.');
            }
            if (Calendar::toDate(CarbonImmutable::now()) <= $bounds['ends_on']) {
                throw new InvalidTransitionException(Periods::label($periodKey).' is not over yet.');
            }
            if (($earlier = $this->earlierOpen($periodKey)) !== null) {
                throw new InvalidTransitionException(Periods::label($earlier).' is still open; close the months in order.');
            }

            $checklist = $this->reconciliation->checklist($bounds['ends_on']);
            if (! CloseChecklist::passes($checklist)) {
                $failed = array_values(array_map(fn (array $c): string => $c['label'], array_filter($checklist, fn (array $c): bool => ! $c['passed'])));
                throw new ConflictException(Periods::label($periodKey).' cannot be closed: '.implode('; ', $failed).'.', ['reason' => 'close_checklist_failed', 'checklist' => $checklist]);
            }

            $actor = User::query()->findOrFail($this->tenancy->require()->userId);
            $before = ['status' => $period->status->value];
            $period->forceFill([
                'status' => PeriodStatus::Closed,
                'closed_at' => CarbonImmutable::now(),
                'closed_by' => $actor->id,
                'closed_by_name' => $actor->name,
                'close_checklist' => $checklist,
            ])->save();
            $this->audit->record($period, 'closed', $before, ['status' => 'closed', 'checklist_passed' => true]);

            return $period;
        });
    }

    /** The earliest open month before this one that has anything in it. */
    private function earlierOpen(string $periodKey): ?string
    {
        $key = Period::query()->where('period_key', '<', $periodKey)->where('status', PeriodStatus::Open->value)
            ->whereExists(fn (QueryBuilder $q) => $q->select(DB::raw('1'))->from('journal_entries as e')->whereColumn('e.period_id', 'periods.id'))
            ->orderBy('period_key')->value('period_key');

        return is_string($key) ? $key : null;
    }
}
