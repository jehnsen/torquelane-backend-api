<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/**
 * Port of ../web/lib/checkin.ts `normalisePlate` / `normaliseVin`. "ABC 1234",
 * "abc-1234" and "ABC1234" are one vehicle. Uniqueness and look-ups use the
 * normalised form; the plate is displayed as typed.
 */
final class PlateNumber
{
    public static function normalise(string $plate): string
    {
        return strtoupper((string) preg_replace('/[\s-]/u', '', $plate));
    }

    public static function normaliseVin(string $vin): string
    {
        return strtoupper((string) preg_replace('/\s/u', '', $vin));
    }
}
