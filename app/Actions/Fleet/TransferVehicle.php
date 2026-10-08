<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Audit\AuditTrail;
use App\Models\CustomerAccount;
use App\Models\Vehicle;
use App\Models\VehicleOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hands a vehicle to another account: the current ownership closes on the
 * effective date and a new one opens. Readings and maintenance state stay
 * with the VEHICLE (the new owner sees its service history); documents stay
 * with the account they were filed under (the new owner does not see the
 * previous owner's papers, and the previous owner keeps their own).
 */
final class TransferVehicle
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly FleetQueries $fleet,
    ) {}

    public function handle(Vehicle $vehicle, CustomerAccount $to, ?string $effectiveOn): Vehicle
    {
        $effectiveOn ??= $this->fleet->todayDate();

        return DB::transaction(function () use ($vehicle, $to, $effectiveOn): Vehicle {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->customer_account_id === $to->id) {
                throw ValidationException::withMessages(['customer_account_id' => 'The vehicle already belongs to this account.']);
            }

            $current = VehicleOwnership::query()->where('vehicle_id', $locked->id)->whereNull('to_date')->lockForUpdate()->first();
            if ($current !== null) {
                if ($effectiveOn < $current->from_date->toDateString()) {
                    throw ValidationException::withMessages(['effective_on' => 'The transfer cannot predate the current ownership.']);
                }
                $current->forceFill(['to_date' => $effectiveOn])->save();
            }

            (new VehicleOwnership)->forceFill([
                'vehicle_id' => $locked->id,
                'customer_account_id' => $to->id,
                'from_date' => $effectiveOn,
            ])->save();

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['customer_account_id' => $to->id])->save();
            $this->audit->record($locked, 'transferred', $before, AuditTrail::snapshot($locked) + ['effective_on' => $effectiveOn]);

            return $locked;
        });
    }
}
