<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Actions\Analytics\AnalyticsQueries;
use App\Actions\Fleet\VehicleView;
use App\Domain\Analytics\AutoSchedule;
use App\Domain\Analytics\ScheduleProposal;
use App\Domain\Shared\WebFormat;
use App\Domain\Tenancy\AccountStanding;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Models\CustomerAccount;
use App\Models\ServiceTask;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * "Auto-schedule overdue" (../web auto-schedule-dialog): one draft work
 * order per overdue item that no live order covers, booked from tomorrow at
 * three a day. The preview and the commit run the same rule; the commit
 * recomputes it inside its transaction with the scope's accounts locked, so
 * two commits never book the same item twice. Accounts that take no new
 * work (suspended) are left out.
 */
final class AutoScheduleWorkOrders
{
    public function __construct(
        private readonly AnalyticsQueries $analytics,
        private readonly CreateWorkOrder $create,
    ) {}

    /**
     * The proposals, their vehicles, and each proposal's estimate in
     * centavos (by proposal index).
     *
     * @return array{proposals: list<ScheduleProposal>, vehicles: array<string, Vehicle>, estimates: list<int>}
     */
    public function preview(?CustomerAccount $account): array
    {
        $views = $this->analytics->vehicles($account);
        $open = [];
        foreach (CustomerAccount::query()->whereIn('id', array_unique(array_map(fn (VehicleView $v): string => $v->vehicle->customer_account_id, $views)))->get(['id', 'status']) as $owner) {
            $open[$owner->id] = AccountStanding::acceptsNewWork($owner->status);
        }
        $views = array_values(array_filter($views, fn (VehicleView $v): bool => $open[$v->vehicle->customer_account_id] ?? false));

        $covered = AutoSchedule::covered(array_map(fn (WorkOrder $o): array => [
            'vehicleId' => $o->vehicle_id,
            'taskIds' => array_values($o->tasks->map(fn (WorkOrderTask $t): string => $t->service_task_id)->all()),
            'live' => ! in_array($o->status, [WorkOrderStatus::Closed, WorkOrderStatus::Cancelled], true),
        ], $this->analytics->workOrders($account)));

        $proposals = AutoSchedule::propose(AnalyticsQueries::health($views), $covered, $this->analytics->fleet()->today());

        $vehicles = [];
        foreach ($views as $view) {
            $vehicles[$view->vehicle->id] = $view->vehicle;
        }
        $tasks = ServiceTask::query()->whereIn('id', array_map(fn (ScheduleProposal $p): string => $p->item->task->id, $proposals))->get()->keyBy('id');
        $rate = $this->labourRate($account);
        $estimates = array_map(function (ScheduleProposal $p) use ($tasks, $rate): int {
            $task = $tasks->get($p->item->task->id);

            return $task instanceof ServiceTask ? self::estimate($task, $rate) : 0;
        }, $proposals);

        return ['proposals' => $proposals, 'vehicles' => $vehicles, 'estimates' => $estimates];
    }

    /**
     * Raises the drafts, within the accounts the caller was authorised for.
     *
     * @param  list<string>  $accountIds
     * @return list<WorkOrder>
     */
    public function handle(?CustomerAccount $account, array $accountIds, ?string $branchId): array
    {
        return DB::transaction(function () use ($account, $accountIds, $branchId): array {
            CustomerAccount::query()->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get();
            $preview = $this->preview($account);
            $today = WebFormat::date($this->analytics->fleet()->todayDate());
            $tasks = ServiceTask::query()->get()->keyBy('id');

            $created = [];
            foreach ($preview['proposals'] as $proposal) {
                $vehicle = $preview['vehicles'][$proposal->vehicleId];
                $task = $tasks->get($proposal->item->task->id);
                if (! $task instanceof ServiceTask || ! in_array($vehicle->customer_account_id, $accountIds, true)) {
                    continue;
                }
                $created[] = $this->create->handle($vehicle, [
                    'title' => $task->name,
                    'type' => 'preventive',
                    'priority' => $proposal->priority,
                    'scheduled_for' => $proposal->scheduledFor,
                    'branch_id' => $branchId,
                    'task_ids' => [$task->id],
                    'notes' => "Raised automatically from the overdue queue on {$today}.",
                ], [[
                    'service_task_id' => $task->id,
                    'description' => $task->name,
                    'category' => $task->category,
                    'quantity' => 1,
                    'unit_part_rate_cents' => $task->estimated_cost_cents->getMinorAmount()->toInt(),
                    'labour_hours' => (string) $task->estimated_hours,
                    'urgency' => $task->critical ? 'safety_critical' : 'recommended',
                ]]);
            }

            return $created;
        });
    }

    /** The draft's own pricing: parts at the catalogue cost, labour hours × rate rounded once (half up). */
    private static function estimate(ServiceTask $task, int $labourRateCents): int
    {
        $labour = BigDecimal::of((string) $task->estimated_hours)->multipliedBy($labourRateCents)->toScale(0, RoundingMode::HalfUp)->toInt();

        return $task->estimated_cost_cents->getMinorAmount()->toInt() + $labour;
    }

    /** The scope's default shop rate (the draft takes its own branch's when priced). */
    private function labourRate(?CustomerAccount $account): int
    {
        return $this->analytics->scopeSettings($account)->defaultLabourRateCents;
    }
}
