<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Actions\Fleet\FleetQueries;
use App\Domain\Access\Capability;
use App\Domain\Approvals\ApprovalAction;
use App\Domain\Approvals\Approvals;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Billing\Billing;
use App\Domain\Fleet\CompletedService;
use App\Domain\Fleet\Pms;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Shared\Num;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\MaintenanceState;
use App\Models\MeterReading;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Models\WorkOrderPart;
use App\Models\WorkOrderTask;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * complete: the technician records what was done — findings, the odometer at
 * service, parts fitted, tasks discharged — while the job is in progress.
 *
 * close: in_progress → closed. Refused when actual cost runs past the
 * approved amount by more than the variance threshold, unless someone with
 * the authority to approve that amount re-approves the variance (logged).
 * Closing resets the PMS clock: every discharged task takes the order's
 * odometer and completion date (Pms::applyCompletion), a higher odometer is
 * recorded as a reading, and the vehicle returns to active — in the same
 * transaction as the order.
 */
final class CompleteWorkOrder
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly WorkOrderJournal $journal,
        private readonly LineWriter $lines,
        private readonly ApprovalSettingsResolver $settings,
        private readonly FleetQueries $fleet,
    ) {}

    /**
     * @param  array{findings?: string, odometer_at_service?: string, parts?: list<array<string, mixed>>, task_ids?: list<string>}  $data
     */
    public function complete(WorkOrder $order, array $data): WorkOrder
    {
        return DB::transaction(function () use ($order, $data): WorkOrder {
            $locked = $this->journal->lock($order);
            if ($locked->status !== WorkOrderStatus::InProgress) {
                throw new InvalidTransitionException('Work is recorded against a job that is in progress.');
            }
            $before = WorkOrderJournal::snapshot($locked);

            $locked->forceFill(array_intersect_key($data, array_flip(['findings', 'odometer_at_service'])))->save();
            if (isset($data['parts'])) {
                WorkOrderPart::query()->where('work_order_id', $locked->id)->delete();
                foreach ($data['parts'] as $position => $part) {
                    $row = new WorkOrderPart;
                    $row->forceFill([
                        'work_order_id' => $locked->id,
                        'position' => $position,
                        'part_number' => is_string($part['part_number'] ?? null) ? $part['part_number'] : null,
                        'name' => is_string($part['name'] ?? null) ? $part['name'] : '',
                        'quantity' => is_int($part['quantity'] ?? null) || is_string($part['quantity'] ?? null) ? (string) $part['quantity'] : '0',
                        'unit_cost_cents' => is_int($part['unit_cost_cents'] ?? null) ? $part['unit_cost_cents'] : 0,
                    ])->save();
                }
            }
            if (isset($data['task_ids'])) {
                $this->lines->replaceTasks($locked, $data['task_ids']);
            }
            $this->journal->audit($locked, 'work_recorded', $before);

            return $locked;
        });
    }

    public function close(WorkOrder $order, bool $varianceApproved): WorkOrder
    {
        $context = $this->tenancy->require();

        return DB::transaction(function () use ($context, $order, $varianceApproved): WorkOrder {
            $locked = $this->journal->lock($order);
            $this->journal->guard($locked, WorkOrderStatus::Closed);
            $before = WorkOrderJournal::snapshot($locked);
            $now = CarbonImmutable::now();
            $today = $now->setTimezone('Asia/Manila')->toDateString();

            // Both sides pre-tax, like the approval bands. Actual labour is the
            // approved lines' labour; actual parts are the parts fitted.
            $billable = array_values($locked->lines->map(fn (WorkOrderLine $l) => $l->billable())->all());
            $partsTotal = 0;
            if ($locked->parts->isNotEmpty()) {
                $sum = BigDecimal::zero();
                foreach ($locked->parts as $part) {
                    $sum = $sum->plus($part->quantity->multipliedBy($part->unit_cost_cents));
                }
                $partsTotal = $sum->toScale(0, RoundingMode::HalfUp)->toInt();
            }
            $actual = Billing::totals($billable, '0', 0, [LineApprovalStatus::Approved])->labourTotalCents + $partsTotal;
            $approved = Approvals::approvedValue($billable);
            $settings = $this->settings->forOrder($locked);

            $breach = Approvals::varianceExceeds($approved, $actual, $settings->varianceThresholdPct);
            if ($breach && ! $varianceApproved) {
                throw new ConflictException(sprintf(
                    'Actual cost (₱%s) exceeds the approved ₱%s by more than %s%% — re-approve the variance before closing.',
                    number_format($actual / 100, 2), number_format($approved / 100, 2), $settings->varianceThresholdPct,
                ), ['reason' => 'variance_exceeded', 'actual_cents' => $actual, 'approved_cents' => $approved, 'threshold_pct' => $settings->varianceThresholdPct]);
            }
            if ($breach && (! $context->can(Capability::WorkOrderApprove) || ! Approvals::canApprove($context->role, $actual, $settings))) {
                throw new AuthorizationException('Re-approving this variance needs someone who can approve the actual amount.');
            }

            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($locked->vehicle_id);
            $facts = $this->fleet->vehicleFacts($vehicle);
            $odometer = $locked->odometer_at_service ?? $locked->odometer_at_intake ?? BigDecimal::of((string) $facts->odometer);
            $taskIds = array_values($locked->tasks->map(fn (WorkOrderTask $t): string => $t->service_task_id)->all());
            $next = Pms::applyCompletion($facts, new CompletedService($taskIds, Num::of($odometer), $today), CarbonImmutable::parse($today, 'Asia/Manila'));

            foreach ($taskIds as $taskId) {
                $state = MaintenanceState::query()
                    ->where('asset_type', Vehicle::ASSET_TYPE)
                    ->where('asset_id', $vehicle->id)
                    ->where('service_task_id', $taskId)
                    ->first() ?? new MaintenanceState;
                $state->forceFill([
                    'asset_type' => Vehicle::ASSET_TYPE,
                    'asset_id' => $vehicle->id,
                    'service_task_id' => $taskId,
                    'vehicle_id' => $vehicle->id,
                    'meter_kind' => MeterKind::Km,
                    'last_done_value' => (string) $odometer,
                    'last_done_on' => $next->taskState[$taskId]->lastDoneOn,
                ])->save();
            }
            if ($odometer->isGreaterThan((string) $facts->odometer) && $today >= $facts->odometerReadAt) {
                $reading = new MeterReading;
                $reading->forceFill([
                    'asset_type' => Vehicle::ASSET_TYPE,
                    'asset_id' => $vehicle->id,
                    'vehicle_id' => $vehicle->id,
                    'meter_kind' => MeterKind::Km,
                    'value' => (string) $odometer,
                    'read_on' => $today,
                    'source' => 'work_order',
                    'recorded_by' => $context->userId,
                ])->save();
            }
            if ($vehicle->status !== $next->status) {
                $vehicle->forceFill(['status' => $next->status])->save();
            }

            if ($breach) {
                $this->journal->log($locked, ApprovalAction::VarianceApproved, null, $actual, sprintf('Actual ₱%s vs approved ₱%s.', number_format($actual / 100, 2), number_format($approved / 100, 2)), $now);
            }
            $locked->forceFill([
                'status' => WorkOrderStatus::Closed,
                'completed_on' => $today,
                'odometer_at_service' => (string) $odometer,
            ])->save();
            $this->journal->event($locked, WorkOrderStatus::Closed, $now);
            $this->journal->audit($locked, 'closed', $before);

            return $locked;
        });
    }
}
