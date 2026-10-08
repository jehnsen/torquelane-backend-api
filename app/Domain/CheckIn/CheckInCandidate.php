<?php

declare(strict_types=1);

namespace App\Domain\CheckIn;

use App\Domain\Fleet\VehicleFacts;

/** A vehicle the caller may check in: already scoped by the caller, never the raw fleet. */
final readonly class CheckInCandidate
{
    public function __construct(
        public VehicleFacts $vehicle,
        public string $vin,
        public ?string $customerAccountId,
        public string $make,
        public string $model,
        public ?int $year,
        public ?string $vehicleClass,
        public ?string $fuelType,
    ) {}
}
