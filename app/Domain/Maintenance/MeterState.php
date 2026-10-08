<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use DateTimeImmutable;

/**
 * An asset's meter as of its latest reading: the value, when it was read, and
 * the average use per day that projects it forward.
 */
final readonly class MeterState
{
    public function __construct(
        public MeterKind $kind,
        public float|int $value,
        public DateTimeImmutable $readOn,
        public float|int $dailyRate,
    ) {}
}
