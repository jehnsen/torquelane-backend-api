<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\WorkOrders\WorkOrderQueries;
use App\Domain\Shop\BayLoad;
use App\Domain\Shop\NamedValue;
use App\Domain\Shop\Shop;
use App\Domain\Shop\TechnicianLoad;
use App\Domain\Shop\UtilisationPoint;
use App\Domain\Shop\WaitingOrder;
use App\Domain\WorkOrders\WorkOrderFacts;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Http\Requests\ShopRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The shop floor (staff, repair module): queues, bays, technicians and
 * revenue, over the branch named by `branch_id` (else X-Branch-Id, else every
 * branch the caller works in). Revenue is recognised on collection.
 */
final class ShopController
{
    public function __construct(
        private readonly WorkOrderQueries $orders,
        private readonly TenantManager $tenancy,
    ) {}

    /**
     * Arriving today
     *
     * Approved or scheduled work booked for `date` (default today).
     */
    public function arriving(ShopRequest $request): JsonResponse
    {
        $facts = $this->facts($request, [WorkOrderStatus::Approved, WorkOrderStatus::Scheduled]);

        return $this->orders($request, Shop::arrivingToday($facts, $request->day()));
    }

    /**
     * In progress
     *
     * On the floor now, longest-running first, with minutes elapsed since start.
     */
    public function inProgress(ShopRequest $request): JsonResponse
    {
        $facts = Shop::inProgress($this->facts($request, [WorkOrderStatus::InProgress]));
        $now = now()->toImmutable();
        $views = $this->views($facts);

        return new JsonResponse(['data' => array_map(fn (WorkOrderFacts $f): array => [
            'elapsed_minutes' => Shop::elapsedMinutes($f, $now),
            'elapsed' => Shop::formatDuration(Shop::elapsedMinutes($f, $now)),
            'work_order' => $views[$f->id],
        ], $facts)]);
    }

    /**
     * Ready for collection
     *
     * Closed, not yet collected: outstanding, oldest completion first.
     */
    public function readyForCollection(ShopRequest $request): JsonResponse
    {
        return $this->orders($request, Shop::readyForCollection($this->facts($request, [WorkOrderStatus::Closed])));
    }

    /**
     * Approval bottleneck
     *
     * Quotations waiting on the customer, longest wait first, in business
     * hours (Mon–Fri 08:00–18:00 Manila), against each order's SLA.
     */
    public function approvals(ShopRequest $request): JsonResponse
    {
        $facts = $this->facts($request, [WorkOrderStatus::PendingApproval]);
        $now = now()->toImmutable();
        $waiting = Shop::approvalBottleneck($facts, $now);
        $summary = Shop::awaitingApproval($facts, $now);
        $facts = array_map(fn (WaitingOrder $w): WorkOrderFacts => $w->order, $waiting);
        $views = $this->views($facts);
        $sla = [];
        foreach ($this->orders->viewsOf(array_map(fn (WorkOrderFacts $f): string => $f->id, $facts)) as $view) {
            $sla[$view->order->id] = $view->settings->slaHours;
        }

        return new JsonResponse(['data' => [
            'count' => $summary->count,
            'total_value_cents' => $summary->totalValueCents,
            'orders' => array_map(fn (WaitingOrder $w): array => [
                'waiting_hours' => $w->hours,
                'sla_hours' => $sla[$w->order->id],
                'breached' => $w->hours > $sla[$w->order->id],
                'work_order' => $views[$w->order->id],
            ], $waiting),
        ]]);
    }

    /**
     * Bay load and floor utilisation
     *
     * Booked hours per bay on `date` (catalogue estimates, else labour at
     * the shop rate, else an hour) against capacity; nothing stops a bay
     * reading over 100%. `days` adds a trailing daily utilisation series.
     */
    public function floor(ShopRequest $request): JsonResponse
    {
        $branchId = $this->branch($request);
        $shop = $this->orders->shopContext($branchId);
        $facts = $this->facts($request, null);
        $floor = Shop::floorUtilisation($facts, $request->day(), $shop);

        return new JsonResponse(['data' => [
            'date' => $request->day()->toDateString(),
            'booked_hours' => $floor->bookedHours,
            'capacity_hours' => $floor->capacityHours,
            'utilisation' => $floor->utilisation,
            'bays' => array_map(fn (BayLoad $load): array => [
                'bay_id' => $load->bayId,
                'name' => $load->name,
                'booked_hours' => $load->bookedHours,
                'capacity_hours' => $load->capacityHours,
                'utilisation' => $load->utilisation,
                'work_order_ids' => array_map(fn (WorkOrderFacts $f): string => $f->id, $load->jobs),
            ], $floor->loads),
            'series' => $request->filled('days') ? array_map(fn (UtilisationPoint $p): array => [
                'date' => $p->key,
                'label' => $p->label,
                'utilisation_pct' => $p->utilisation,
                'booked_hours' => $p->bookedHours,
            ], Shop::utilisationSeries($facts, $request->integer('days'), $request->day()->setTime(12, 0), $shop)) : null,
        ]]);
    }

    /**
     * Technician load
     *
     * Per technician: the job in hand, jobs closed in [`from`, `to`), and mean
     * actual hours (start → close event) against the estimate.
     */
    public function technicians(ShopRequest $request): JsonResponse
    {
        $branchId = $this->branch($request);
        $technicians = $this->orders->technicians($branchId);
        $loads = Shop::technicianLoad(array_keys($technicians), $this->facts($request, null), $request->from(), $request->to(), $this->orders->shopContext($branchId));

        return new JsonResponse(['data' => array_map(fn (TechnicianLoad $t): array => [
            'technician_id' => $t->technician,
            'name' => $technicians[$t->technician]->name ?? '',
            'current_work_order_id' => $t->current?->id,
            'completed_this_period' => $t->completedThisPeriod,
            'avg_actual_hours' => $t->avgActualHours,
            'avg_estimated_hours' => $t->avgEstimatedHours,
        ], $loads)]);
    }

    /**
     * Revenue
     *
     * Recognised on collection, in [`from`, `to`) (default: this month to
     * date), by customer account and by service item.
     */
    public function revenue(ShopRequest $request): JsonResponse
    {
        $branchId = $this->branch($request);
        $facts = $this->facts($request, [WorkOrderStatus::Closed]);
        $from = $request->from();
        $to = $request->to();
        $accounts = $this->orders->accountsOf($facts);

        return new JsonResponse(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue_cents' => Shop::revenueBetween($facts, $from, $to),
            'by_customer' => self::moneyRows(Shop::revenueByAccount($accounts, $facts, $from, $to)),
            'by_service_item' => self::moneyRows(Shop::revenueByServiceItem($facts, $from, $to, $this->orders->shopContext($branchId))),
        ]]);
    }

    /**
     * @param  list<NamedValue>  $values  centavos
     * @return list<array{name: string, value_cents: int}>
     */
    private static function moneyRows(array $values): array
    {
        return array_map(fn (NamedValue $v): array => ['name' => $v->name, 'value_cents' => is_int($v->value) ? $v->value : (int) round($v->value)], $values);
    }

    /**
     * @param  list<WorkOrderStatus>|null  $statuses
     * @return list<WorkOrderFacts>
     */
    private function facts(ShopRequest $request, ?array $statuses): array
    {
        Gate::authorize('viewShop', WorkOrder::class);

        return $this->orders->visibleFacts($statuses, $this->branch($request));
    }

    private function branch(ShopRequest $request): ?string
    {
        $context = $this->tenancy->require();
        $branchId = $request->branchId() ?? $context->selectedBranchId;
        if ($branchId !== null && ! $context->branchAllowed($branchId)) {
            throw ValidationException::withMessages(['branch_id' => 'You do not work in that branch.']);
        }

        return $branchId;
    }

    /**
     * @param  list<WorkOrderFacts>  $facts
     */
    private function orders(Request $request, array $facts): JsonResponse
    {
        return new JsonResponse(['data' => array_values($this->views($facts))]);
    }

    /**
     * @param  list<WorkOrderFacts>  $facts
     * @return array<string, array<string, mixed>>
     */
    private function views(array $facts): array
    {
        $out = [];
        foreach ($this->orders->viewsOf(array_map(fn (WorkOrderFacts $f): string => $f->id, $facts)) as $view) {
            $resolved = [];
            foreach ((new WorkOrderResource($view))->resolve(request()) as $key => $value) {
                $resolved[(string) $key] = $value;
            }
            $out[$view->order->id] = $resolved;
        }

        return $out;
    }
}
