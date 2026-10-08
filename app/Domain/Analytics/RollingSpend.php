<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

final readonly class RollingSpend
{
    public function __construct(
        /** Closed spend over the trailing window. */
        public int $currentCents,
        /** Closed spend over the window immediately before it. */
        public int $previousCents,
        /** Percentage change, rounded; 0 when there is no prior spend. */
        public int $deltaPct,
        public int $windowDays,
    ) {}
}
