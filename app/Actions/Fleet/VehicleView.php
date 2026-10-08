<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\VehicleFacts;
use App\Domain\Fleet\VehicleHealth;
use App\Models\Vehicle;

/**
 * A vehicle with everything computed about it, so responses carry the
 * numbers and the frontend renders rather than computes.
 */
final readonly class VehicleView
{
    public function __construct(
        public Vehicle $vehicle,
        public VehicleFacts $facts,
        /** Null where repair_pms is not active for the session. */
        public ?VehicleHealth $health,
        /** expired | expiring | ok */
        public string $complianceStatus,
        public int $odometerAgeDays,
        public bool $odometerStale,
    ) {}
}
