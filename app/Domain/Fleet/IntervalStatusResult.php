<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Maintenance\IntervalBand;
use DateTimeImmutable;

/** Port of ../web's `IntervalStatus` (one km meter + a calendar interval). */
final readonly class IntervalStatusResult
{
    public function __construct(
        public float|int $distanceDueAt,
        public DateTimeImmutable $distanceDueOn,
        public DateTimeImmutable $timeDueAt,
        /** `distance` or `time`. */
        public string $governedBy,
        public DateTimeImmutable $projectedDue,
        public IntervalBand $status,
        public float|int|null $kmPast,
        public ?int $daysOverdue,
        public float|int $estimatedOdometerNow,
        public int $daysRemaining,
        public float|int $kmRemaining,
        public float|int $progress,
    ) {}
}
