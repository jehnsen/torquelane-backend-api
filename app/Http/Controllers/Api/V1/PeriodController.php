<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ledger\ClosePeriod;
use App\Actions\Ledger\LedgerQueries;
use App\Domain\Ledger\Periods;
use App\Domain\Shared\Calendar;
use App\Http\Requests\ClosePeriodRequest;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Accounting periods and the close checklist (Phase 8).
 */
final class PeriodController
{
    /**
     * Periods
     *
     * `ledger:view`. The months that have entries, newest first, with their
     * status and (once closed) who closed them and the checklist as it
     * passed. The current month is always listed.
     */
    public function index(LedgerQueries $ledger): JsonResponse
    {
        Gate::authorize('viewAny', Period::class);
        $periods = $ledger->periods();
        $current = Periods::keyOf(Calendar::toDate(CarbonImmutable::now()));
        $rows = array_map(fn (Period $p): array => [
            'period_key' => $p->period_key,
            'label' => Periods::label($p->period_key),
            'starts_on' => $p->starts_on->toDateString(),
            'ends_on' => $p->ends_on->toDateString(),
            'status' => $p->status->value,
            'closed_at' => $p->closed_at?->toIso8601ZuluString(),
            'closed_by_name' => $p->closed_by_name,
        ], $periods);
        if (! in_array($current, array_map(fn (array $r): string => $r['period_key'], $rows), true)) {
            ['starts_on' => $starts, 'ends_on' => $ends] = Periods::bounds($current);
            array_unshift($rows, ['period_key' => $current, 'label' => Periods::label($current), 'starts_on' => $starts, 'ends_on' => $ends, 'status' => 'open', 'closed_at' => null, 'closed_by_name' => null]);
        }

        return new JsonResponse(['data' => $rows, 'meta' => ['current' => $current]]);
    }

    /**
     * Close checklist
     *
     * `ledger:view`, `period` `YYYY-MM`. The five checks for that month (every
     * source posted; receivables = Accounts Receivable; stock valuation =
     * Inventory; unapplied credit = Customer Deposits; trial balance
     * balanced), whether the month can be closed now and, if not, why.
     * Read-only.
     */
    public function checklist(ClosePeriodRequest $request, ClosePeriod $close): JsonResponse
    {
        Gate::authorize('viewAny', Period::class);

        return new JsonResponse(['data' => $close->preview($request->period())]);
    }

    /**
     * Close a month
     *
     * `ledger:manage` (and not branch-limited). Only once the month is over,
     * earlier months are closed and the checklist passes (409
     * `close_checklist_failed` carries it). A closed month takes no new entry
     * and is never reopened; a void of one of its documents posts in the
     * current month, dated today, naming the original.
     */
    public function close(ClosePeriodRequest $request, ClosePeriod $close): JsonResponse
    {
        Gate::authorize('close', Period::class);
        $period = $close->close($request->period());

        return new JsonResponse(['data' => $close->preview($period->period_key)]);
    }
}
