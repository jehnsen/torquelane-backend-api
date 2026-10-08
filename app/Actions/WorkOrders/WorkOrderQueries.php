<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\Shared\Num;
use App\Domain\Shop\AccountRef;
use App\Domain\Shop\BayFacts;
use App\Domain\Shop\ShopContext;
use App\Domain\WorkOrders\StatusEvent;
use App\Domain\WorkOrders\WorkOrderFacts;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Models\ApprovalSetting;
use App\Models\Bay;
use App\Models\CustomerAccount;
use App\Models\ServiceTask;
use App\Models\Technician;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use App\Models\WorkOrderLine;
use App\Models\WorkOrderPart;
use App\Models\WorkOrderTask;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * @phpstan-type WorkOrderFilters array{status?: list<string>, customer_account_id?: string, vehicle_id?: string, branch_id?: string, scheduled_for?: string, technician_id?: string, bay_id?: string, stage?: string, type?: string, q?: string, sort?: string}
 *
 * The read side of work orders: what the caller may see, as models or as the
 * domain snapshot (WorkOrderFacts) the approval and shop rules read.
 */
final class WorkOrderQueries
{
    public const array RELATIONS = ['lines', 'tasks', 'parts', 'events', 'approvalLog'];

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly ApprovalSettingsResolver $settings,
    ) {}

    /**
     * @return Builder<WorkOrder>
     */
    public function orders(): Builder
    {
        return WorkOrder::query()->visibleTo($this->tenancy->require());
    }

    /**
     * @param  WorkOrderFilters  $filters
     * @return LengthAwarePaginator<int, WorkOrderView>
     */
    public function page(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->filtered($filters)->with(self::RELATIONS);
        match ($filters['sort'] ?? 'opened') {
            'scheduled' => $query->orderByRaw('scheduled_for asc nulls first')->orderBy('id'),
            'completed' => $query->orderByRaw('completed_on desc nulls last')->orderByDesc('id'),
            'queue' => $query->orderByRaw("status <> 'in_progress'")->orderByRaw('scheduled_for asc nulls first')->orderBy('id'),
            default => $query->orderByDesc('opened_on')->orderByDesc('id'),
        };

        $page = $query->paginate($perPage);

        return new Paginator($this->views(array_values($page->items())), $page->total(), $page->perPage(), $page->currentPage());
    }

    /**
     * The visible orders matching the list filters (no ordering).
     *
     * @param  WorkOrderFilters  $filters
     * @return Builder<WorkOrder>
     */
    public function filtered(array $filters): Builder
    {
        $query = $this->orders();
        if (isset($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }
        foreach (['customer_account_id', 'vehicle_id', 'branch_id', 'scheduled_for', 'technician_id', 'bay_id', 'type'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        match ($filters['stage'] ?? null) {
            'active' => $query->whereNotIn('status', [WorkOrderStatus::Closed->value, WorkOrderStatus::Cancelled->value]),
            'completed' => $query->where('status', WorkOrderStatus::Closed->value),
            'cancelled' => $query->where('status', WorkOrderStatus::Cancelled->value),
            default => null,
        };
        if (($filters['q'] ?? '') !== '') {
            $like = '%'.addcslashes(mb_strtolower((string) $filters['q']), '%_\\').'%';
            $plates = Vehicle::query()->visibleTo($this->tenancy->require())->select('id')->whereRaw('lower(plate_number) like ?', [$like]);
            $customers = CustomerAccount::query()->visibleTo($this->tenancy->require())->select('id')->whereRaw('lower(display_name) like ?', [$like]);
            $query->where(fn (Builder $w) => $w
                ->whereRaw('lower(reference) like ?', [$like])
                ->orWhereRaw('lower(title) like ?', [$like])
                ->orWhereRaw("lower(coalesce(technician_name, '')) like ?", [$like])
                ->orWhereRaw('lower(vendor) like ?', [$like])
                ->orWhereIn('vehicle_id', $plates)
                ->orWhereIn('customer_account_id', $customers));
        }

        return $query;
    }

    /**
     * What the list screens total: orders per bucket under the scope filters
     * (account, vehicle, branch), and the count and value (workOrderCost:
     * labour plus resolved parts, centavos) of the fully filtered set.
     *
     * @param  WorkOrderFilters  $filters
     * @return array{buckets: array{active: int, completed: int, cancelled: int, all: int}, filtered: array{count: int, value_cents: int}}
     */
    public function summary(array $filters): array
    {
        $scope = array_intersect_key($filters, array_flip(['customer_account_id', 'vehicle_id', 'branch_id']));
        $counts = $this->filtered($scope)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $buckets = ['active' => 0, 'completed' => 0, 'cancelled' => 0, 'all' => 0];
        foreach ($counts as $status => $n) {
            $n = is_numeric($n) ? (int) $n : 0;
            $buckets['all'] += $n;
            $buckets[match ((string) $status) {
                WorkOrderStatus::Closed->value => 'completed',
                WorkOrderStatus::Cancelled->value => 'cancelled',
                default => 'active',
            }] += $n;
        }

        $value = 0;
        $count = 0;
        foreach ($this->filtered($filters)->with(self::RELATIONS)->lazyById(200) as $order) {
            $count++;
            $value += $this->fact($order)->costCents();
        }

        return ['buckets' => $buckets, 'filtered' => ['count' => $count, 'value_cents' => $value]];
    }

    public function view(WorkOrder $order): WorkOrderView
    {
        return $this->views([$order])[0];
    }

    /**
     * @param  list<WorkOrder>  $orders
     * @return list<WorkOrderView>
     */
    public function views(array $orders): array
    {
        $settings = [];

        return array_map(function (WorkOrder $order) use (&$settings): WorkOrderView {
            $key = $order->customer_account_id.'|'.$order->branch_id;
            $settings[$key] ??= $this->settings->forOrder($order);

            return new WorkOrderView($order->loadMissing(self::RELATIONS), $settings[$key]);
        }, $orders);
    }

    /**
     * Visible orders by id, relations loaded.
     *
     * @param  list<string>  $ids
     * @return list<WorkOrderView>
     */
    public function viewsOf(array $ids): array
    {
        $orders = $this->orders()->with(self::RELATIONS)->whereIn('id', $ids)->get()->keyBy('id');

        return $this->views(array_values(array_filter(array_map(fn (string $id): ?WorkOrder => $orders->get($id), $ids))));
    }

    /**
     * @param  list<WorkOrder>  $orders  relations loaded
     * @return list<WorkOrderFacts>
     */
    public function facts(array $orders): array
    {
        return array_map($this->fact(...), $orders);
    }

    public function fact(WorkOrder $order): WorkOrderFacts
    {
        $order->loadMissing(self::RELATIONS);

        return new WorkOrderFacts(
            $order->id,
            $order->status,
            $order->vehicle_id,
            $order->customer_account_id,
            $order->scheduled_for?->toDateString(),
            $order->bay_id,
            array_values($order->tasks->map(fn (WorkOrderTask $task): string => $task->service_task_id)->all()),
            $order->labor_cost_cents,
            $order->parts_cost_cents,
            array_values($order->parts->map(fn (WorkOrderPart $part) => $part->facts())->all()),
            array_values($order->lines->map(fn (WorkOrderLine $line) => $line->billable())->all()),
            array_values($order->events->map(fn (WorkOrderEvent $event): StatusEvent => new StatusEvent($event->status, $event->at->toImmutable()))->all()),
            $order->completed_on?->toDateString(),
            $order->collected_at,
            $order->technician_id ?? '',
            $order->pending_approval_entered_at,
            $order->approval_wait_hours === null ? null : Num::of($order->approval_wait_hours),
        );
    }

    /**
     * Everything visible in the given statuses, as facts.
     *
     * @param  list<WorkOrderStatus>|null  $statuses
     * @return list<WorkOrderFacts>
     */
    public function visibleFacts(?array $statuses = null, ?string $branchId = null): array
    {
        $query = $this->orders()->with(self::RELATIONS)->orderBy('id');
        if ($statuses !== null) {
            $query->whereIn('status', array_map(fn (WorkOrderStatus $s): string => $s->value, $statuses));
        }
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $this->facts(array_values($query->get()->all()));
    }

    /**
     * Active technicians of a branch (or the caller's branches), by id.
     *
     * @return array<string, Technician>
     */
    public function technicians(?string $branchId): array
    {
        $branches = $branchId !== null ? [$branchId] : $this->tenancy->require()->branchFilter();

        $technicians = [];
        foreach (Technician::query()->whereIn('branch_id', $branches)->where('status', 'active')->orderBy('name')->orderBy('id')->get() as $technician) {
            $technicians[$technician->id] = $technician;
        }

        return $technicians;
    }

    /**
     * @param  list<WorkOrderFacts>  $facts
     * @return list<AccountRef>
     */
    public function accountsOf(array $facts): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (WorkOrderFacts $f): ?string => $f->customerAccountId, $facts))));

        return array_values(CustomerAccount::query()->whereIn('id', $ids)->orderBy('display_name')->orderBy('id')->get()
            ->map(fn (CustomerAccount $a): AccountRef => new AccountRef($a->id, $a->display_name))->all());
    }

    /**
     * A branch's sparse approval override (empty when it has none).
     *
     * @return array<string, int|string>
     */
    public function branchOverride(string $branchId): array
    {
        return ApprovalSetting::query()->where('branch_id', $branchId)->first()?->values() ?? [];
    }

    /**
     * The floor a branch (or every branch the caller may see) works with.
     */
    public function shopContext(?string $branchId): ShopContext
    {
        $context = $this->tenancy->require();
        $branches = $branchId !== null ? [$branchId] : $context->branchFilter();

        $bays = Bay::query()->whereIn('branch_id', $branches)->where('status', 'active')->orderBy('name')->orderBy('id')->get();
        $tasks = ServiceTask::query()->orderBy('position')->orderBy('id')->get();

        $hours = [];
        $names = [];
        foreach ($tasks as $task) {
            $hours[$task->id] = Num::of($task->estimated_hours);
            $names[$task->id] = $task->name;
        }

        return new ShopContext(
            array_values($bays->map(fn (Bay $bay): BayFacts => new BayFacts($bay->id, $bay->name, Num::of($bay->capacity_hours_per_day)))->all()),
            $hours,
            $names,
            $this->settings->forBranch($branchId)->defaultLabourRateCents,
        );
    }
}
