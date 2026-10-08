<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\AnalyticsQueries;
use App\Actions\Fleet\FleetQueries;
use App\Actions\Fleet\VehicleView;
use App\Actions\WorkOrders\ApprovalSettingsResolver;
use App\Actions\WorkOrders\WorkOrderQueries;
use App\Domain\Analytics\Analytics;
use App\Domain\Shared\BusinessHours;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;
use App\Domain\Shop\AccountRef;
use App\Domain\Shop\AccountRollup;
use App\Domain\Shop\BayLoad;
use App\Domain\Shop\NamedValue;
use App\Domain\Shop\Shop;
use App\Domain\Shop\TechnicianLoad;
use App\Domain\Shop\UtilisationPoint;
use App\Domain\Shop\WaitingOrder;
use App\Domain\WorkOrders\WorkOrderFacts;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Http\Requests\ShopRequest;
use App\Http\Resources\AnalyticsJson;
use App\Http\Resources\CustomerAccountResource;
use App\Http\Resources\VehicleResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\CustomerAccount;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
        private readonly AnalyticsQueries $analytics,
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
            // (actual − estimate) / estimate, whole percent; null without both.
            'variance_pct' => $t->avgActualHours !== null && $t->avgEstimatedHours !== null && $t->avgEstimatedHours > 0
                ? (int) JsMath::round(($t->avgActualHours - $t->avgEstimatedHours) / $t->avgEstimatedHours * 100)
                : null,
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
     * Shop today
     *
     * The shop home in one call: arriving today, on the floor (elapsed
     * against the estimate), quotes waiting on the customer (oldest first,
     * the longest wait in business hours), ready for collection (with the
     * value on the lot), bay utilisation today, and revenue this week
     * (Monday start, Manila) against last week. Each order carries its
     * vehicle and customer name.
     */
    public function home(ShopRequest $request): JsonResponse
    {
        $branchId = $this->branch($request);
        $facts = $this->facts($request, null);
        $shop = $this->orders->shopContext($branchId);
        $now = now()->toImmutable();
        $day = $request->day();

        $arriving = Shop::arrivingToday($facts, $day);
        $running = Shop::inProgress($facts);
        $approvals = Shop::awaitingApproval($facts, $now);
        $waiting = $approvals->orders;
        usort($waiting, fn (WorkOrderFacts $a, WorkOrderFacts $b): int => ($a->pendingApprovalEnteredAt?->getTimestamp() ?? 0) <=> ($b->pendingApprovalEnteredAt?->getTimestamp() ?? 0));
        $collectable = Shop::readyForCollection($facts);
        $floor = Shop::floorUtilisation($facts, $day, $shop);

        $weekStart = CarbonImmutable::now('Asia/Manila')->startOfWeek(CarbonInterface::MONDAY);
        $lastWeekStart = $weekStart->subDays(7);
        $thisWeek = Shop::revenueBetween($facts, $weekStart, $now);
        $lastWeek = Shop::revenueBetween($facts, $lastWeekStart, $weekStart);

        $views = $this->labelled(array_merge($arriving, $running, $waiting, $collectable));
        $view = fn (WorkOrderFacts $f): array => $views[$f->id];

        return new JsonResponse(['data' => [
            'date' => $day->toDateString(),
            'arriving' => array_map($view, $arriving),
            'in_progress' => array_map(fn (WorkOrderFacts $f): array => [
                'elapsed_minutes' => Shop::elapsedMinutes($f, $now),
                'elapsed' => Shop::formatDuration(Shop::elapsedMinutes($f, $now)),
                'estimated_hours' => Shop::estimatedHours($f, $shop),
                'over_estimate' => Shop::elapsedMinutes($f, $now) !== null && Shop::elapsedMinutes($f, $now) / 60 > Shop::estimatedHours($f, $shop),
                'work_order' => $view($f),
            ], $running),
            'awaiting_approval' => [
                'count' => $approvals->count,
                'total_value_cents' => $approvals->totalValueCents,
                'longest' => $approvals->longest === null ? null : ['work_order_id' => $approvals->longest->order->id, 'hours' => $approvals->longest->hours],
                'orders' => array_map(fn (WorkOrderFacts $f): array => [
                    'quoted_value_cents' => array_sum(array_map(fn ($line): int => $line->partCostCents + $line->labourCostCents, $f->lines)),
                    'waiting_hours' => $f->pendingApprovalEnteredAt === null ? 0 : BusinessHours::between($f->pendingApprovalEnteredAt, $now),
                    'work_order' => $view($f),
                ], $waiting),
            ],
            'ready_for_collection' => [
                'count' => count($collectable),
                /** The estimate's labour + parts of every finished job still on the lot. */
                'value_cents' => array_sum(array_map(fn (WorkOrderFacts $f): int => $f->laborCostCents + $f->partsCostCents, $collectable)),
                'orders' => array_map($view, $collectable),
            ],
            'floor' => [
                'booked_hours' => $floor->bookedHours,
                'capacity_hours' => $floor->capacityHours,
                'utilisation' => $floor->utilisation,
                'bays_working' => count(array_filter($floor->loads, fn (BayLoad $l): bool => $l->jobs !== [])),
                'bays' => array_map(fn (BayLoad $load): array => [
                    'bay_id' => $load->bayId,
                    'name' => $load->name,
                    'booked_hours' => $load->bookedHours,
                    'capacity_hours' => $load->capacityHours,
                    'utilisation' => $load->utilisation,
                ], $floor->loads),
            ],
            'revenue' => [
                'week_start' => $weekStart->toDateString(),
                'this_week_cents' => $thisWeek,
                'last_week_cents' => $lastWeek,
                'delta_pct' => $lastWeek !== 0 ? (int) JsMath::round(($thisWeek - $lastWeek) / $lastWeek * 100) : 0,
            ],
        ]]);
    }

    /**
     * Shop reports
     *
     * Over the last `months` (3, 6 or 12; default 6), recognised on
     * collection: revenue by customer and by service item (top 10), approval
     * turnaround by customer, the maintenance mix by month, and the parts
     * margin on closed work; plus 21 days of bay utilisation.
     */
    public function reports(ShopRequest $request): JsonResponse
    {
        $branchId = $this->branch($request);
        $facts = $this->facts($request, null);
        $shop = $this->orders->shopContext($branchId);
        $now = now()->toImmutable();
        $months = $request->integer('months', 6);
        $from = Calendar::addMonths(Calendar::local($now), -$months);
        $accounts = $this->orders->accountsOf($facts);
        $margin = Shop::partsMargin($facts);
        // The mix reads the same orders (branch-narrowed) as analytics orders.
        $inScope = array_flip(array_map(fn (WorkOrderFacts $f): string => $f->id, $facts));
        $mix = $this->analytics->analyticsOrders(array_values(array_filter($this->analytics->workOrders(null), fn (WorkOrder $o): bool => isset($inScope[$o->id]))));

        return new JsonResponse(['data' => [
            'months' => $months,
            'from' => Calendar::toDate($from),
            'to' => Calendar::toDate($now),
            'revenue_by_customer' => self::moneyRows(Shop::revenueByAccount($accounts, $facts, $from, $now)),
            'revenue_by_service_item' => self::moneyRows(array_slice(Shop::revenueByServiceItem($facts, $from, $now, $shop), 0, 10)),
            'utilisation' => array_map(fn (UtilisationPoint $p): array => [
                'date' => $p->key,
                'label' => $p->label,
                'utilisation_pct' => $p->utilisation,
                'booked_hours' => $p->bookedHours,
            ], Shop::utilisationSeries($facts, 21, $now, $shop)),
            'approval_turnaround_by_customer' => array_map(fn (NamedValue $v): array => ['name' => $v->name, 'hours' => $v->value, 'meta' => $v->meta], Shop::approvalTurnaroundByAccount($accounts, $facts)),
            'maintenance_mix' => AnalyticsJson::monthly(Analytics::monthlyCosts($mix, $months, Calendar::local($now))),
            'parts_margin' => [
                'supplier_provided_cents' => $margin->supplierProvidedCents,
                'own_stock_cents' => $margin->ownStockCents,
                'margin_cents' => $margin->marginCents,
                'markup_pct' => BigDecimal::of(Shop::PARTS_MARKUP)->multipliedBy(100)->toInt(),
            ],
        ]]);
    }

    /**
     * Clients
     *
     * Every customer account the shop looks after, biggest spender this
     * month first: vehicles, open work, mean approval turnaround, revenue
     * recognised this month (to now), and closed work not yet collected. An
     * order counts for the account stamped on it at creation.
     */
    public function clients(ShopRequest $request): JsonResponse
    {
        $facts = $this->facts($request, null);
        $accounts = CustomerAccount::query()->visibleTo($this->tenancy->require())->orderBy('display_name')->orderBy('id')->get();
        $rollups = $this->rollups(array_values($accounts->all()), $facts);
        usort($rollups, fn (AccountRollup $a, AccountRollup $b): int => $b->spendThisPeriodCents <=> $a->spendThisPeriodCents);
        $byId = $accounts->keyBy('id');

        return new JsonResponse(['data' => array_map(fn (AccountRollup $r): array => self::rollup($r, $byId->get($r->account->id)), $rollups)]);
    }

    /**
     * A client
     *
     * One customer account from the shop's side: its rollup, its vehicles
     * (with PMS health where repair_pms is on), its work-order history
     * (latest first), and its terms: the account, its sparse approval
     * overrides, and the settings they resolve to.
     */
    public function client(ShopRequest $request, CustomerAccount $customerAccount, FleetQueries $fleet, ApprovalSettingsResolver $settings): JsonResponse
    {
        Gate::authorize('view', $customerAccount);
        $branchId = $this->branch($request);
        Gate::authorize('viewShop', WorkOrder::class);

        $facts = array_values(array_filter($this->orders->visibleFacts(null, $branchId), fn (WorkOrderFacts $f): bool => $f->customerAccountId === $customerAccount->id));
        [$rollup] = $this->rollups([$customerAccount], $facts);

        /** @var list<Vehicle> $vehicles */
        $vehicles = array_values($fleet->vehicles()->where('customer_account_id', $customerAccount->id)->orderBy('plate_normalized')->orderBy('id')->get()->all());
        $views = $fleet->views($vehicles);

        usort($facts, fn (WorkOrderFacts $a, WorkOrderFacts $b): int => strcmp($b->completedOn ?? $b->scheduledFor ?? '', $a->completedOn ?? $a->scheduledFor ?? ''));
        $labelled = $this->labelled($facts);

        return new JsonResponse(['data' => [
            'rollup' => self::rollup($rollup, $customerAccount),
            'overdue_vehicles' => count(array_filter($views, fn (VehicleView $v): bool => $v->health?->status === 'overdue')),
            'account' => (new CustomerAccountResource($customerAccount))->resolve($request),
            'approval_overrides' => (object) ($customerAccount->approval_threshold_overrides ?? []),
            'effective_settings' => $settings->forAccount($customerAccount, $branchId)->toArray(),
            'vehicles' => array_map(fn (VehicleView $v): array => (new VehicleResource($v))->resolve($request), $views),
            'work_orders' => array_map(fn (WorkOrderFacts $f): array => $labelled[$f->id], $facts),
        ]]);
    }

    /**
     * @param  list<CustomerAccount>  $accounts
     * @param  list<WorkOrderFacts>  $facts
     * @return list<AccountRollup>
     */
    private function rollups(array $accounts, array $facts): array
    {
        $ids = array_map(fn (CustomerAccount $a): string => $a->id, $accounts);
        $counts = [];
        foreach (Vehicle::query()->visibleTo($this->tenancy->require())->whereNull('archived_at')->whereIn('customer_account_id', $ids)->get(['id', 'customer_account_id']) as $vehicle) {
            $counts[$vehicle->customer_account_id] = ($counts[$vehicle->customer_account_id] ?? 0) + 1;
        }
        $now = now()->toImmutable();

        return Shop::rollupAccounts(
            array_map(fn (CustomerAccount $a): AccountRef => new AccountRef($a->id, $a->display_name), $accounts),
            $counts,
            $facts,
            Calendar::local($now)->modify('first day of this month')->setTime(0, 0),
            $now,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function rollup(AccountRollup $rollup, ?CustomerAccount $account): array
    {
        return [
            'customer_account_id' => $rollup->account->id,
            'name' => $rollup->account->name,
            'account_type' => $account?->account_type,
            'status' => $account?->status,
            'vehicle_count' => $rollup->vehicleCount,
            'open_work_orders' => $rollup->openWorkOrders,
            'avg_approval_hours' => $rollup->avgApprovalHours,
            'spend_this_period_cents' => $rollup->spendThisPeriodCents,
            'outstanding_cents' => $rollup->outstandingCents,
        ];
    }

    /**
     * Work orders as the shop lists render them, each with its vehicle and
     * customer name.
     *
     * @param  list<WorkOrderFacts>  $facts
     * @return array<string, array<string, mixed>>
     */
    private function labelled(array $facts): array
    {
        $context = $this->tenancy->require();
        $vehicles = Vehicle::query()->visibleTo($context)->whereIn('id', array_map(fn (WorkOrderFacts $f): string => $f->vehicleId, $facts))->get()->keyBy('id');
        $names = CustomerAccount::query()->visibleTo($context)->whereIn('id', array_filter(array_map(fn (WorkOrderFacts $f): ?string => $f->customerAccountId, $facts)))->pluck('display_name', 'id');

        $out = [];
        foreach ($this->views($facts) as $id => $view) {
            $vehicleId = is_string($view['vehicle_id'] ?? null) ? $view['vehicle_id'] : '';
            $accountId = is_string($view['customer_account_id'] ?? null) ? $view['customer_account_id'] : '';
            $name = $names->get($accountId);
            $out[$id] = $view + [
                'vehicle' => AnalyticsJson::vehicle($vehicles->get($vehicleId)),
                'customer_name' => is_string($name) ? $name : null,
            ];
        }

        return $out;
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
