<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** A vehicle as the rankings read it (plate, make and model as labels). */
final readonly class AnalyticsVehicle
{
    public function __construct(
        public string $id,
        public string $plateNumber,
        public string $make,
        public string $model,
        public float|int $odometer,
        public float|int $avgDailyKm,
    ) {}
}
