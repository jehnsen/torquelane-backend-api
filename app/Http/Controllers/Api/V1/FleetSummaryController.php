<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fleet\FleetQueries;
use App\Http\Resources\FleetSummaryResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class FleetSummaryController
{
    /**
     * Fleet summary
     *
     * The dashboard KPIs over the caller's vehicles (staff may narrow to one
     * `customer_account_id`), the expiring-documents tile and the thresholds
     * behind them. Needs repair_pms.
     */
    public function __invoke(Request $request, FleetQueries $fleet): FleetSummaryResource
    {
        Gate::authorize('viewFleetSummary', Vehicle::class);

        $accountId = $request->filled('customer_account_id')
            ? $fleet->account($request->string('customer_account_id')->lower()->toString())->id
            : null;

        [$summary, $expiring, $compliance] = $fleet->summary($accountId);

        return new FleetSummaryResource($summary, $expiring, $compliance, $fleet->todayDate());
    }
}
