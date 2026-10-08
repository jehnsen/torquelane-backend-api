<?php

declare(strict_types=1);

namespace App\Domain\CheckIn;

/** The counter form, hydrated (../web checkin.ts CheckInFormState). */
final readonly class CheckInForm
{
    public function __construct(
        public ?string $vehicleId = null,
        public string $plateNumber = '',
        public string $vin = '',
        public string $make = '',
        public string $model = '',
        public ?int $year = null,
        public ?string $vehicleClass = null,
        public ?string $fuelType = null,
        public string $customerName = '',
        public string $customerContact = '',
        public string $customerEmail = '',
        public string $assignedTo = '',
        public float|int|null $odometer = null,
        public bool $odometerNeedsConfirmation = false,
        public bool $isExistingVehicle = false,
    ) {}
}
