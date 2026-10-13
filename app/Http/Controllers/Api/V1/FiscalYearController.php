<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ledger\CloseYear;
use App\Domain\Shared\Calendar;
use App\Http\Requests\CloseYearRequest;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Fiscal years (Phase 12). The fiscal year is the calendar year; closing one
 * moves its profit or loss to Retained Earnings and closes its December.
 */
final class FiscalYearController
{
    /**
     * Fiscal years
     *
     * `ledger:view`, from a sign-in that is not branch-limited (the figures
     * are the whole organization's). Every closed year and the years since that are still
     * open, newest first (or just `year`): revenue, expenses and profit over
     * the whole organization, whether the year can be closed now and, if
     * not, why.
     */
    public function index(CloseYearRequest $request, CloseYear $years): JsonResponse
    {
        Gate::authorize('viewAny', FiscalYear::class);
        if ($request->year() !== null) {
            return new JsonResponse(['data' => [$years->preview($request->year())]]);
        }

        $current = (int) substr(Calendar::toDate(CarbonImmutable::now()), 0, 4);
        $first = FiscalYear::query()->min('year');
        $rows = [];
        for ($year = $current; $year >= min(is_numeric($first) ? (int) $first : $current - 1, $current - 1); $year--) {
            $rows[] = $years->preview($year);
        }

        return new JsonResponse(['data' => $rows]);
    }

    /**
     * Close a fiscal year
     *
     * `ledger:manage`, from a sign-in that is not branch-limited; `year`.
     * Allowed once the year is over, every earlier year with entries is
     * closed, and December's own close would pass. Posts the closing entry
     * (every revenue and expense account back to zero, the difference to
     * Retained Earnings, branch by branch) and closes December, in one
     * transaction. Final.
     */
    public function close(CloseYearRequest $request, CloseYear $years): JsonResponse
    {
        Gate::authorize('close', FiscalYear::class);
        $year = (int) $request->year();
        $years->close($year);

        return new JsonResponse(['data' => $years->preview($year)]);
    }
}
