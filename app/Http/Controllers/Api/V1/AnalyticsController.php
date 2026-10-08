<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\AnalyticsQueries;
use App\Actions\Fleet\VehicleView;
use App\Actions\WorkOrders\AutoScheduleWorkOrders;
use App\Actions\WorkOrders\WorkOrderQueries;
use App\Domain\Access\Capability;
use App\Domain\Analytics\Analytics;
use App\Domain\Analytics\AnalyticsOrder;
use App\Domain\Analytics\AutoSchedule;
use App\Domain\Analytics\ScheduleProposal;
use App\Domain\Analytics\UrgentItem;
use App\Domain\Fleet\Pms;
use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\JsMath;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Http\Requests\AnalyticsRequest;
use App\Http\Resources\AnalyticsJson;
use App\Http\Resources\FleetSummaryResource;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Tenancy\TenantManager;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Purpose-built reads for the fleet screens (../web lib/analytics.ts):
 * the dashboard, the service schedule and the cost reports, each in one
 * call over the caller's scope (staff may narrow to one
 * `customer_account_id`). Money in centavos; months and day keys are
 * Asia/Manila. Needs repair_pms.
 */
final class AnalyticsController
{
    /** How many urgent items the dashboard's attention list shows. */
    private const int ATTENTION_PREVIEW = 3;

    /** How many active work orders the dashboard previews. */
    private const int ACTIVE_PREVIEW = 5;

    public function __construct(
        private readonly AnalyticsQueries $analytics,
        private readonly WorkOrderQueries $orders,
    ) {}

    /**
     * Fleet dashboard
     *
     * The fleet overview in one call: the fleet summary, overdue and
     * due-soon demand (`serviceDemand`), 30-day rolling spend, twelve months
     * of closed cost, the six-week upcoming load, stale odometers, expiring
     * documents, the three most urgent items, and the next five active work
     * orders by scheduled date.
     */
    public function dashboard(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('viewFleetSummary', Vehicle::class);
        $account = $this->analytics->account($request->accountId());
        $fleet = $this->analytics->fleet();
        $today = $fleet->today();

        $views = $this->analytics->vehicles($account);
        $health = AnalyticsQueries::health($views);
        $models = $this->analytics->workOrders($account);
        $orders = $this->analytics->analyticsOrders($models);
        $vehicles = self::vehiclesById($views);
        $tasks = $this->analytics->tasks();
        $costs = $this->analytics->taskCosts();

        [$summary, $expiring, $compliance] = $fleet->summary($account?->id);
        $urgent = Analytics::urgentItems($health);

        $active = array_values(array_filter($models, fn (WorkOrder $o): bool => ! in_array($o->status, [WorkOrderStatus::Closed, WorkOrderStatus::Cancelled], true)));
        usort($active, fn (WorkOrder $a, WorkOrder $b): int => strcmp($a->scheduled_for?->toDateString() ?? '', $b->scheduled_for?->toDateString() ?? ''));
        $preview = $this->orders->views(array_slice($active, 0, self::ACTIVE_PREVIEW));

        return new JsonResponse(['data' => [
            'as_of' => $fleet->todayDate(),
            'customer_account_id' => $account?->id,
            'summary' => (new FleetSummaryResource($summary, $expiring, $compliance, $fleet->todayDate()))->resolve($request),
            'vehicles_in_operation' => $summary->total - $summary->inService - $summary->down,
            'demand' => AnalyticsJson::demand(Analytics::serviceDemand($health, $costs)),
            'spend' => AnalyticsJson::spend(Analytics::rollingSpend($orders, 30, $today)),
            'monthly_costs' => AnalyticsJson::monthly(Analytics::monthlyCosts($orders, 12, $today)),
            'upcoming_load' => AnalyticsJson::upcoming(Analytics::upcomingLoad($health, 6, $today)),
            'stale_odometers' => count(array_filter($views, fn (VehicleView $v): bool => $v->odometerStale)),
            'attention' => [
                'total' => count($urgent),
                'items' => array_map(
                    fn (UrgentItem $u): array => AnalyticsJson::item($request, $vehicles[$u->vehicle->id] ?? null, $u->item, $tasks, $costs),
                    array_slice($urgent, 0, self::ATTENTION_PREVIEW),
                ),
            ],
            'active_work_orders' => [
                'total' => count($active),
                'preview' => array_map(fn ($view): array => AnalyticsJson::workOrder($request, $view, $vehicles), $preview),
            ],
        ]]);
    }

    /**
     * Service schedule
     *
     * Every tracked interval across the fleet, most urgent first
     * (`compareUrgency`), grouped by lead time: overdue, next 7, next 30 and
     * next 90 days (each with its catalogue cost). `status` (ok | due_soon |
     * overdue) filters the rows; the six-week load and the demand bands are
     * always the whole fleet's, the same figures the dashboard shows.
     */
    public function schedule(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('viewFleetSummary', Vehicle::class);
        $account = $this->analytics->account($request->accountId());
        $today = $this->analytics->fleet()->today();

        $views = $this->analytics->vehicles($account);
        $health = AnalyticsQueries::health($views);
        $vehicles = self::vehiclesById($views);
        $tasks = $this->analytics->tasks();
        $costs = $this->analytics->taskCosts();
        $status = $request->pmsStatus();

        /** @var list<array{vehicle: string, item: PmsItem}> $rows */
        $rows = [];
        foreach ($health as $entry) {
            foreach ($entry->items as $item) {
                if ($status === null || $item->status === $status) {
                    $rows[] = ['vehicle' => $entry->vehicle->id, 'item' => $item];
                }
            }
        }
        usort($rows, fn (array $a, array $b): int => Pms::compareUrgency($a['item'], $b['item']));

        $groups = [
            ['key' => 'overdue', 'label' => 'Overdue', 'description' => 'Past a limit. These should be booked in today.', 'test' => fn (PmsItem $i): bool => $i->status === 'overdue'],
            ['key' => 'next_7_days', 'label' => 'Next 7 days', 'description' => 'Falling due inside the week.', 'test' => fn (PmsItem $i): bool => $i->status !== 'overdue' && $i->daysRemaining <= 7],
            ['key' => 'next_30_days', 'label' => 'Next 30 days', 'description' => 'Enough lead time to order parts.', 'test' => fn (PmsItem $i): bool => $i->status !== 'overdue' && $i->daysRemaining > 7 && $i->daysRemaining <= 30],
            ['key' => 'next_90_days', 'label' => 'Next 90 days', 'description' => 'On the horizon; useful for budgeting.', 'test' => fn (PmsItem $i): bool => $i->status !== 'overdue' && $i->daysRemaining > 30 && $i->daysRemaining <= 90],
        ];

        $listed = 0;
        $out = [];
        foreach ($groups as $group) {
            $members = array_values(array_filter($rows, fn (array $row): bool => $group['test']($row['item'])));
            $listed += count($members);
            $out[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'description' => $group['description'],
                'count' => count($members),
                'estimated_cost_cents' => array_sum(array_map(fn (array $row): int => $costs[$row['item']->task->id] ?? 0, $members)),
                'rows' => array_map(fn (array $row): array => AnalyticsJson::item($request, $vehicles[$row['vehicle']] ?? null, $row['item'], $tasks, $costs), $members),
            ];
        }

        return new JsonResponse(['data' => [
            'as_of' => $this->analytics->fleet()->todayDate(),
            'customer_account_id' => $account?->id,
            'status' => $status ?? 'all',
            'upcoming_load' => AnalyticsJson::upcoming(Analytics::upcomingLoad($health, 6, $today)),
            'demand' => AnalyticsJson::demand(Analytics::serviceDemand($health, $costs)),
            'listed' => $listed,
            'beyond_horizon' => count($rows) - $listed,
            'groups' => $out,
        ]]);
    }

    /**
     * Cost reports
     *
     * Over the last `months` (3, 6 or 12; default 12) of closed work: monthly
     * cost and maintenance mix, total and preventive share of spend, cost per
     * fleet kilometre (distance driven IN the period, from each vehicle's
     * daily average), mean days between services, and the rankings: costliest
     * vehicles, spend by service item, services per 10,000 km.
     */
    public function reports(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('viewFleetSummary', Vehicle::class);
        $account = $this->analytics->account($request->accountId());
        $today = $this->analytics->fleet()->today();
        $months = $request->months();

        $views = $this->analytics->vehicles($account);
        $health = AnalyticsQueries::health($views);
        $orders = $this->analytics->analyticsOrders($this->analytics->workOrders($account));
        $rankable = AnalyticsQueries::rankable(array_values(array_filter($views, fn (VehicleView $v): bool => $v->health instanceof VehicleHealth)));

        $costs = Analytics::monthlyCosts($orders, $months, $today);
        $keys = array_map(fn ($point): string => $point->key, $costs);
        $scoped = array_values(array_filter($orders, fn (AnalyticsOrder $o): bool => $o->isClosed() && $o->completedOn !== null && in_array(substr($o->completedOn, 0, 7), $keys, true)));

        $total = array_sum(array_map(fn (AnalyticsOrder $o): int => $o->costCents, $scoped));
        $preventive = array_sum(array_map(fn (AnalyticsOrder $o): int => $o->type !== 'corrective' ? $o->costCents : 0, $scoped));
        $periodKm = Analytics::fleetKmInPeriod(AnalyticsQueries::rankable($views), (int) JsMath::round($months * 30.44));
        $vehicleCount = count($health);

        return new JsonResponse(['data' => [
            'as_of' => $this->analytics->fleet()->todayDate(),
            'customer_account_id' => $account?->id,
            'months' => $months,
            'closed_orders' => count($scoped),
            'vehicle_count' => $vehicleCount,
            'total_spend_cents' => $total,
            'preventive_spend_cents' => $preventive,
            'preventive_share_pct' => $total !== 0 ? (int) JsMath::round($preventive / $total * 100) : 0,
            'period_km' => $periodKm,
            /** Centavos per km driven in the period, rounded once (half up). */
            'cost_per_km_cents' => $periodKm !== 0 ? BigRational::of($total)->dividedBy($periodKm)->toScale(0, RoundingMode::HalfUp)->toInt() : 0,
            'mean_days_between_services' => Analytics::meanDaysBetweenServices($scoped, $vehicleCount, $months),
            'monthly_costs' => AnalyticsJson::monthly($costs),
            'spend_by_vehicle' => AnalyticsJson::named(Analytics::spendByVehicle($scoped, $rankable, 8), true),
            'spend_by_service_item' => AnalyticsJson::named(array_slice(Analytics::spendByCategory($scoped, $this->analytics->fleet()->taskFacts()), 0, 8), true),
            'service_frequency' => AnalyticsJson::named(Analytics::serviceFrequency($scoped, $rankable, 10), false),
        ]]);
    }

    /**
     * Auto-schedule preview
     *
     * What "Auto-schedule overdue" would book: every overdue item no live
     * work order covers, worst first, from tomorrow at three a day (critical
     * tasks as critical, the rest high), each with its estimate (catalogue
     * parts + hours at the shop rate), and the total. Accounts that take no
     * new work are left out. `POST /work-orders/auto-schedule` books exactly
     * this, recomputed. `can_commit`: the caller holds `workorder:create`.
     */
    public function autoSchedule(AnalyticsRequest $request, AutoScheduleWorkOrders $schedule, TenantManager $tenancy): JsonResponse
    {
        Gate::authorize('viewFleetSummary', Vehicle::class);
        $account = $this->analytics->account($request->accountId());
        $preview = $schedule->preview($account);
        $tasks = $this->analytics->tasks();
        $costs = $this->analytics->taskCosts();

        return new JsonResponse(['data' => [
            'as_of' => $this->analytics->fleet()->todayDate(),
            'customer_account_id' => $account?->id,
            'jobs_per_day' => AutoSchedule::JOBS_PER_DAY,
            'can_commit' => $tenancy->require()->can(Capability::WorkOrderCreate),
            'count' => count($preview['proposals']),
            'estimate_cents' => array_sum($preview['estimates']),
            'proposals' => array_map(fn (ScheduleProposal $p, int $estimate): array => AnalyticsJson::item($request, $preview['vehicles'][$p->vehicleId] ?? null, $p->item, $tasks, $costs) + [
                'scheduled_for' => $p->scheduledFor,
                'priority' => $p->priority,
                'estimate_cents' => $estimate,
            ], $preview['proposals'], $preview['estimates']),
        ]]);
    }

    /**
     * @param  list<VehicleView>  $views
     * @return array<string, Vehicle>
     */
    private static function vehiclesById(array $views): array
    {
        $vehicles = [];
        foreach ($views as $view) {
            $vehicles[$view->vehicle->id] = $view->vehicle;
        }

        return $vehicles;
    }
}
