<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fleet\FleetQueries;
use App\Actions\Parts\PartsQueries;
use App\Domain\Parts\DemandContributor;
use App\Domain\Parts\PartDemandRow;
use App\Domain\Tenancy\AccountStanding;
use App\Http\Requests\DemandForecastRequest;
use App\Models\FleetPart;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class DemandForecastController
{
    /**
     * Parts demand forecast
     *
     * One customer account's projected spare-parts demand over
     * `horizon_weeks` (default 6; ../web `computePartsDemand`): its vehicles'
     * PMS items falling due, less what live work orders and open purchase
     * orders already cover, against the account's own stock. Rows rank by
     * shortfall, then quantity required. `summary` is the frontend's
     * sentence; `can_raise` says whether the caller may turn rows into
     * purchase requests (`po:issue`, and the account takes new work). Needs
     * repair_pms.
     */
    public function __invoke(DemandForecastRequest $request, FleetQueries $fleet, PartsQueries $parts): JsonResponse
    {
        $account = $fleet->account($request->accountId());
        Gate::authorize('forecast', [FleetPart::class, $account]);

        $horizon = $request->horizonWeeks();
        $rows = $parts->forecast($account, $horizon);
        $plates = $parts->plates($account);
        $tasks = $fleet->tasksById();

        $shortfallCents = 0;
        $atRisk = 0;
        foreach ($rows as $row) {
            $shortfallCents += $row->estimatedCostCents;
            $atRisk += $row->leadTimeRisk ? 1 : 0;
        }

        return new JsonResponse(['data' => [
            'customer_account_id' => $account->id,
            'horizon_weeks' => $horizon,
            'as_of' => $fleet->todayDate(),
            'summary' => $parts->summary($rows, $horizon),
            'can_raise' => Gate::allows('create', [PurchaseOrder::class, $account]) && AccountStanding::acceptsNewWork($account->status),
            'totals' => [
                'parts' => count($rows),
                'with_shortfall' => count(array_filter($rows, fn (PartDemandRow $r): bool => $r->shortfall > 0)),
                'lead_time_risks' => $atRisk,
                'estimated_cost_cents' => $shortfallCents,
            ],
            'rows' => array_map(fn (PartDemandRow $row): array => [
                'part' => [
                    'id' => $row->part->id,
                    'sku' => $row->part->sku,
                    'name' => $row->part->name,
                    'category' => $row->part->category,
                    'unit' => $row->part->unit,
                    'unit_cost_cents' => $row->part->unitCostCents,
                    'current_stock' => $row->part->currentStock,
                    'reorder_point' => $row->part->reorderPoint,
                    'preferred_vendor' => $row->part->preferredVendor,
                    'lead_time_days' => $row->part->leadTimeDays,
                ],
                'quantity_required' => $row->quantityRequired,
                'shortfall' => $row->shortfall,
                'estimated_cost_cents' => $row->estimatedCostCents,
                'earliest_needed_on' => $row->earliestNeededOn,
                'lead_time_risk' => $row->leadTimeRisk,
                /** Only a row with a shortfall can be requested. */
                'selectable' => $row->shortfall > 0,
                'contributing_items' => array_map(fn (DemandContributor $c): array => [
                    'vehicle_id' => $c->vehicleId,
                    'plate_number' => $plates[$c->vehicleId] ?? '',
                    'service_task_id' => $c->taskId,
                    'task_name' => $tasks[$c->taskId]->name ?? '',
                    'due_date' => $c->dueDate,
                ], $row->contributingItems),
            ], $rows),
        ]]);
    }
}
