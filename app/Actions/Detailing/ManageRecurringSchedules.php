<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Detailing\SizeClass;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\DetailingPackage;
use App\Models\DetailingRecurringSchedule;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's standing weekly slot (the source's `recurring_schedules`):
 * added, switched on and off, and removed. As in the source it is a
 * preference the shop books from; saving one books nothing.
 */
final class ManageRecurringSchedules
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{package_id: string, size_class: string, vehicle_id?: string|null, day_of_week: int, start_minutes: int, is_active?: bool, notes?: string|null}  $data
     */
    public function save(Branch $branch, CustomerAccount $account, ?DetailingRecurringSchedule $schedule, array $data): DetailingRecurringSchedule
    {
        return DB::transaction(function () use ($branch, $account, $schedule, $data): DetailingRecurringSchedule {
            $before = $schedule === null ? null : AuditTrail::snapshot($schedule);
            $schedule ??= (new DetailingRecurringSchedule)->forceFill([
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'customer_account_id' => $account->id,
            ]);

            if (! DetailingPackage::query()->where('branch_id', $branch->id)->whereKey($data['package_id'])->exists()) {
                throw ValidationException::withMessages(['package_id' => 'That package is not on this branch\'s menu.']);
            }
            $vehicleId = $data['vehicle_id'] ?? null;
            if ($vehicleId !== null && ! Vehicle::query()->where('customer_account_id', $account->id)->whereKey($vehicleId)->exists()) {
                throw ValidationException::withMessages(['vehicle_id' => 'That vehicle is not this customer\'s.']);
            }

            $schedule->forceFill([
                'package_id' => $data['package_id'],
                'size_class' => SizeClass::from($data['size_class']),
                'vehicle_id' => $vehicleId,
                'day_of_week' => $data['day_of_week'],
                'start_minutes' => $data['start_minutes'],
                'is_active' => $data['is_active'] ?? (! $schedule->exists || $schedule->is_active),
                'notes' => trim($data['notes'] ?? '') === '' ? null : trim((string) $data['notes']),
            ])->save();
            $this->audit->record($schedule, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($schedule));

            return $schedule;
        });
    }

    public function delete(DetailingRecurringSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule): void {
            $this->audit->record($schedule, 'deleted', AuditTrail::snapshot($schedule), null);
            $schedule->delete();
        });
    }
}
