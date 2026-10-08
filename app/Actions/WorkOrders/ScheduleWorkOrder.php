<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\Modules\Module;
use App\Domain\WorkOrders\WorkOrderMachine;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Models\Bay;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * schedule (approved work onto a bay's calendar; a scheduled order may be
 * re-booked) and start (onto the floor). The bay fixes the branch: an order
 * not yet in one is taken into the bay's; an order already in one books only
 * that branch's bays. Nothing stops two jobs sharing a bay — the floor's
 * utilisation simply reads over 100%.
 */
final class ScheduleWorkOrder
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly WorkOrderJournal $journal,
        private readonly ModuleGate $modules,
        private readonly WorkOrderBranch $branches,
    ) {}

    public function schedule(WorkOrder $order, string $date, string $time, string $bayId, ?string $technicianId): WorkOrder
    {
        return DB::transaction(function () use ($order, $date, $time, $bayId, $technicianId): WorkOrder {
            $locked = $this->journal->lock($order);
            $rebooking = $locked->status === WorkOrderStatus::Scheduled;
            if (! $rebooking) {
                $this->journal->guard($locked, WorkOrderStatus::Scheduled);
            }
            $before = WorkOrderJournal::snapshot($locked);

            $bay = $this->bay($bayId, $locked->branch_id);
            $branchId = $bay->branch_id;
            $this->modules->ensure(Module::RepairPms, $branchId);

            $locked->forceFill([
                'branch_id' => $branchId,
                'bay_id' => $bay->id,
                'scheduled_for' => $date,
                'scheduled_time' => $time,
                'status' => WorkOrderStatus::Scheduled,
                'assigned_branch_id' => $locked->assigned_branch_id ?? WorkOrderMachine::assignOnApproval($locked->vendor, $branchId)['assigned_branch_id'],
            ] + $this->technician($technicianId, $branchId))->save();

            if (! $rebooking) {
                $this->journal->event($locked, WorkOrderStatus::Scheduled, CarbonImmutable::now());
            }
            $this->journal->audit($locked, $rebooking ? 'rescheduled' : 'scheduled', $before);

            return $locked;
        });
    }

    public function start(WorkOrder $order, ?string $technicianId): WorkOrder
    {
        return DB::transaction(function () use ($order, $technicianId): WorkOrder {
            $locked = $this->journal->lock($order);
            $this->journal->guard($locked, WorkOrderStatus::InProgress);
            $before = WorkOrderJournal::snapshot($locked);

            $branchId = $locked->branch_id;
            if ($branchId === null && $this->tenancy->require()->isStaff()) {
                $branchId = $this->branches->forNewWork(null);
            }
            if ($branchId !== null) {
                $this->modules->ensure(Module::RepairPms, $branchId);
            }
            if ($technicianId !== null && $branchId === null) {
                throw ValidationException::withMessages(['technician_id' => 'Take the order into a branch before assigning a technician.']);
            }

            $locked->forceFill([
                'branch_id' => $branchId,
                'status' => WorkOrderStatus::InProgress,
                'assigned_branch_id' => $locked->assigned_branch_id ?? ($branchId === null ? null : WorkOrderMachine::assignOnApproval($locked->vendor, $branchId)['assigned_branch_id']),
            ] + ($branchId === null ? [] : $this->technician($technicianId, $branchId)))->save();

            $this->journal->event($locked, WorkOrderStatus::InProgress, CarbonImmutable::now());
            $this->journal->audit($locked, 'started', $before);

            return $locked;
        });
    }

    private function bay(string $bayId, ?string $branchId): Bay
    {
        $context = $this->tenancy->require();
        $bay = Bay::query()->whereKey($bayId)->where('status', 'active')->first();
        if ($bay === null || ! $context->branchAllowed($bay->branch_id) || ($branchId !== null && $bay->branch_id !== $branchId)) {
            throw ValidationException::withMessages(['bay_id' => 'Choose an active bay in the order\'s branch.']);
        }

        return $bay;
    }

    /**
     * @return array<string, string>
     */
    private function technician(?string $technicianId, string $branchId): array
    {
        if ($technicianId === null) {
            return [];
        }
        $technician = Technician::query()->whereKey($technicianId)->where('branch_id', $branchId)->where('status', 'active')->first();
        if ($technician === null) {
            throw ValidationException::withMessages(['technician_id' => 'Choose an active technician in the order\'s branch.']);
        }

        return ['technician_id' => $technician->id, 'technician_name' => $technician->name];
    }
}
