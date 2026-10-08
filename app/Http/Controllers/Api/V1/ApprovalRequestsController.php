<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\AnalyticsQueries;
use App\Domain\Approvals\ApprovalRequests;
use App\Domain\Approvals\PendingRequest;
use App\Domain\WorkOrders\WorkOrderReference;
use App\Http\Requests\AnalyticsRequest;
use App\Http\Resources\AnalyticsJson;
use App\Models\WorkOrder;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ApprovalRequestsController
{
    /**
     * Approval requests
     *
     * The approvals queue (../web's Requests screen): quotations pending
     * approval, oldest first, each with its pending value, business hours
     * waited against its own SLA, and whether the caller may decide it; what
     * waits on the caller; approved work not yet booked; spend committed
     * this Manila month against the scope's monthly budget; and the mean
     * approval turnaround. Every order is judged against its OWN effective
     * settings. Staff may narrow to one `customer_account_id`. Needs
     * repair_pms.
     */
    public function __invoke(AnalyticsRequest $request, AnalyticsQueries $analytics, TenantManager $tenancy): JsonResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);
        $account = $analytics->account($request->accountId());
        $context = $tenancy->require();

        $models = $analytics->workOrders($account);
        $byId = [];
        foreach ($models as $model) {
            $byId[$model->id] = $model;
        }
        $vehicles = [];
        foreach ($analytics->vehicles($account) as $view) {
            $vehicles[$view->vehicle->id] = $view->vehicle;
        }

        $settings = $analytics->scopeSettings($account);
        $summary = ApprovalRequests::summarise($context->role, $analytics->requestOrders($models), $settings->monthlyBudgetCents, now()->toImmutable());

        return new JsonResponse(['data' => [
            'customer_account_id' => $account?->id,
            'my_pending' => ['line_count' => $summary->myPendingLineCount, 'value_cents' => $summary->myPendingValueCents],
            'awaiting_scheduling' => $summary->awaitingScheduling,
            'committed_this_period_cents' => $summary->committedThisPeriodCents,
            'monthly_budget_cents' => $summary->monthlyBudgetCents,
            'budget_used_pct' => $summary->budgetUsedPct,
            'avg_turnaround_hours' => $summary->avgTurnaroundHours,
            'pending' => array_map(function (PendingRequest $p) use ($byId, $vehicles): array {
                $order = $byId[$p->order->id];

                return [
                    'work_order_id' => $order->id,
                    'reference' => $order->reference,
                    'display_reference' => WorkOrderReference::display($order->reference),
                    'title' => $order->title,
                    'priority' => $order->priority,
                    'vehicle' => AnalyticsJson::vehicle($vehicles[$order->vehicle_id] ?? null),
                    'pending_approval_entered_at' => $order->pending_approval_entered_at?->toIso8601ZuluString(),
                    'pending_value_cents' => $p->pendingValueCents,
                    'waiting_hours' => $p->waitingHours,
                    'sla_hours' => $p->order->settings->slaHours,
                    'breached' => $p->breached,
                    'can_approve' => $p->canApprove,
                ];
            }, $summary->pending),
        ]]);
    }
}
