<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** Overdue: past a limit. Due soon: inside the warning threshold, not past it. */
final readonly class ServiceDemand
{
    public function __construct(
        public DemandBand $overdue,
        public DemandBand $dueSoon,
    ) {}
}
