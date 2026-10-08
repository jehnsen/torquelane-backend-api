<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Audit\AuditTrail;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edits a vehicle's details. Never the odometer (readings go through
 * RecordReading, which validates them) and never the owner (TransferVehicle).
 */
final class UpdateVehicle
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveVehicleRequest
     */
    public function handle(Vehicle $vehicle, array $attributes): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $attributes): Vehicle {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            $before = AuditTrail::snapshot($locked);

            $locked->forceFill(VehicleIdentity::normalise($attributes, $locked->id))->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * Archives (never deletes): history stays, the plate is freed for reuse.
     */
    public function archive(Vehicle $vehicle): Vehicle
    {
        return DB::transaction(function () use ($vehicle): Vehicle {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->isArchived()) {
                return $locked;
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['archived_at' => CarbonImmutable::now('UTC')])->save();
            $this->audit->record($locked, 'archived', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }
}
