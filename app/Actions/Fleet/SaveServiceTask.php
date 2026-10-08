<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Audit\AuditTrail;
use App\Exceptions\ConflictException;
use App\Models\MaintenanceState;
use App\Models\ServiceTask;
use App\Models\WorkOrderLine;
use App\Models\WorkOrderTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The PMS catalogue. Editing an interval NEVER touches a vehicle's recorded
 * state (maintenance_states): due dates move because they are derived from
 * the new interval; when a task was last done does not.
 */
final class SaveServiceTask
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ServiceTask
    {
        return DB::transaction(function () use ($attributes): ServiceTask {
            $this->assertCodeFree($attributes, null);
            $max = ServiceTask::query()->max('position');
            $attributes['position'] ??= (is_numeric($max) ? (int) $max : -1) + 1;

            $task = new ServiceTask;
            $task->forceFill($attributes)->save();
            $this->audit->record($task, 'created', null, AuditTrail::snapshot($task));

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ServiceTask $task, array $attributes): ServiceTask
    {
        return DB::transaction(function () use ($task, $attributes): ServiceTask {
            $locked = ServiceTask::query()->lockForUpdate()->findOrFail($task->id);
            $this->assertCodeFree($attributes, $locked->id);

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /** Deletes a task no vehicle has history for; otherwise deactivate it (409). */
    public function delete(ServiceTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $locked = ServiceTask::query()->lockForUpdate()->findOrFail($task->id);
            if (MaintenanceState::query()->where('service_task_id', $locked->id)->exists()) {
                throw new ConflictException('Vehicles have service history for this task. Deactivate it (is_active: false) instead.');
            }
            // Work orders and purchase orders keep the task they named (R7):
            // the foreign keys restrict, so refuse here rather than fail there.
            if (WorkOrderLine::query()->where('service_task_id', $locked->id)->exists()
                || WorkOrderTask::query()->where('service_task_id', $locked->id)->exists()
                || DB::table('purchase_order_line_tasks')->where('service_task_id', $locked->id)->exists()) {
                throw new ConflictException('Work orders or purchase orders name this task. Deactivate it (is_active: false) instead.');
            }

            $before = AuditTrail::snapshot($locked);
            $locked->delete();
            $this->audit->record($locked, 'deleted', $before, null);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertCodeFree(array $attributes, ?string $except): void
    {
        if (! isset($attributes['code'])) {
            return;
        }

        $taken = ServiceTask::query()
            ->where('code', $attributes['code'])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => 'Another task already uses this code.']);
        }
    }
}
