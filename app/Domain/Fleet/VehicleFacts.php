<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/**
 * A vehicle as the PMS engine sees it. `odometer`, `odometerReadAt` and
 * `avgDailyKm` are derived from meter readings (MeterRate); `taskState` from
 * maintenance_states.
 */
final readonly class VehicleFacts
{
    /**
     * @param  array<string, TaskState>  $taskState  by service task id
     */
    public function __construct(
        public string $id,
        public string $plateNumber,
        public float|int $odometer,
        /** Y-m-d of the latest reading. */
        public string $odometerReadAt,
        public float|int $avgDailyKm,
        public array $taskState,
        /** active | in_service | down */
        public string $status,
        public ?string $driverLicenceExpiry,
        public string $assignedTo,
    ) {}

    /**
     * @param  array<string, TaskState>  $taskState
     */
    public function with(float|int $odometer, string $odometerReadAt, array $taskState, string $status): self
    {
        return new self($this->id, $this->plateNumber, $odometer, $odometerReadAt, $this->avgDailyKm, $taskState, $status, $this->driverLicenceExpiry, $this->assignedTo);
    }
}
