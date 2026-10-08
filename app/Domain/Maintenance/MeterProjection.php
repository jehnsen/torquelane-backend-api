<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use DateTimeImmutable;

/** One meter's limit, projected onto the calendar from the last service. */
final readonly class MeterProjection
{
    public function __construct(
        public MeterKind $kind,
        /** Meter value at which the limit falls due. */
        public float|int $dueAt,
        /** Calendar date the limit falls (or fell) due at the current rate; null when it never will. */
        public ?DateTimeImmutable $dueOn,
        /** The meter projected to today from its last reading. */
        public float|int $estimatedNow,
        /** Signed amount to the limit: negative once past. */
        public float|int $remaining,
        /** 0–1 through the interval; above 1 once breached. */
        public float|int $progress,
    ) {}
}
