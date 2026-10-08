<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Audit\AuditTrail;
use App\Domain\Maintenance\MeterKind;
use App\Models\CustomerAccount;
use App\Models\MaintenanceState;
use App\Models\MeterReading;
use App\Models\ServiceTask;
use App\Models\Vehicle;
use App\Models\VehicleOwnership;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a vehicle under an account, in one transaction: the vehicle, its
 * first ownership, its first odometer reading (a vehicle always has a
 * current reading), and any known service history. With one reading the
 * daily rate is 0, so the calendar limit governs until a second reading.
 */
final class CreateVehicle
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
        private readonly FleetQueries $fleet,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated vehicle columns
     * @param  array{value: string, read_on: string|null}  $odometer
     * @param  list<array{service_task_id: string, last_done_value: string, last_done_on: string}>  $history
     */
    public function handle(CustomerAccount $account, array $attributes, array $odometer, array $history): Vehicle
    {
        $context = $this->tenancy->require();
        $today = $this->fleet->todayDate();
        $readOn = $odometer['read_on'] ?? $today;
        if ($readOn > $today) {
            throw ValidationException::withMessages(['odometer.read_on' => 'A reading cannot be dated in the future.']);
        }

        return DB::transaction(function () use ($context, $account, $attributes, $odometer, $history, $readOn, $today): Vehicle {
            $vehicle = new Vehicle;
            $vehicle->forceFill(['customer_account_id' => $account->id] + VehicleIdentity::normalise($attributes, null))->save();

            $ownership = new VehicleOwnership;
            $ownership->forceFill([
                'vehicle_id' => $vehicle->id,
                'customer_account_id' => $account->id,
                'from_date' => $vehicle->acquired_on?->toDateString() ?? $today,
            ])->save();

            $reading = new MeterReading;
            $reading->forceFill([
                'asset_type' => Vehicle::ASSET_TYPE,
                'asset_id' => $vehicle->id,
                'vehicle_id' => $vehicle->id,
                'meter_kind' => MeterKind::Km,
                'value' => $odometer['value'],
                'read_on' => $readOn,
                'source' => 'manual',
                'recorded_by' => $context->userId,
            ])->save();

            foreach ($history as $entry) {
                if (! ServiceTask::query()->whereKey($entry['service_task_id'])->exists()) {
                    throw ValidationException::withMessages(['service_history' => 'Unknown service task.']);
                }
                (new MaintenanceState)->forceFill([
                    'asset_type' => Vehicle::ASSET_TYPE,
                    'asset_id' => $vehicle->id,
                    'vehicle_id' => $vehicle->id,
                    'service_task_id' => $entry['service_task_id'],
                    'meter_kind' => MeterKind::Km,
                    'last_done_value' => $entry['last_done_value'],
                    'last_done_on' => $entry['last_done_on'],
                ])->save();
            }

            $this->audit->record($vehicle, 'created', null, AuditTrail::snapshot($vehicle) + [
                'odometer' => $odometer['value'],
                'odometer_read_on' => $readOn,
                'service_history' => $history,
            ]);

            return $vehicle;
        });
    }
}
