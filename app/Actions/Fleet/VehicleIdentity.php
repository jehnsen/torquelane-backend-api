<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\PlateNumber;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

/**
 * Normalised plate and VIN, unique among the organization's unarchived
 * vehicles (also partial unique indexes). Matching is exact on the
 * normalised form, as ../web/lib/checkin.ts does: "ABC 1234" and "abc-1234"
 * are one plate.
 */
final class VehicleIdentity
{
    /**
     * Adds `plate_normalized` / `vin_normalized` for whichever of plate and
     * VIN $attributes carry, after checking they are free.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalise(array $attributes, ?string $exceptVehicleId): array
    {
        if (is_string($attributes['plate_number'] ?? null)) {
            $plate = PlateNumber::normalise($attributes['plate_number']);
            if ($plate === '') {
                throw ValidationException::withMessages(['plate_number' => 'Enter the plate number.']);
            }
            if (self::taken('plate_normalized', $plate, $exceptVehicleId)) {
                throw ValidationException::withMessages(['plate_number' => 'Another vehicle already has this plate.']);
            }
            $attributes['plate_normalized'] = $plate;
        }

        if (array_key_exists('vin', $attributes)) {
            $vin = is_string($attributes['vin']) ? PlateNumber::normaliseVin($attributes['vin']) : '';
            if ($vin !== '' && self::taken('vin_normalized', $vin, $exceptVehicleId)) {
                throw ValidationException::withMessages(['vin' => 'Another vehicle already has this VIN.']);
            }
            $attributes['vin_normalized'] = $vin === '' ? null : $vin;
        }

        return $attributes;
    }

    private static function taken(string $column, string $value, ?string $exceptVehicleId): bool
    {
        return Vehicle::query()
            ->whereNull('archived_at')
            ->where($column, $value)
            ->when($exceptVehicleId !== null, fn ($query) => $query->whereKeyNot($exceptVehicleId))
            ->lockForUpdate()
            ->exists();
    }
}
