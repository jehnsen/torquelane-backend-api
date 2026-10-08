<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

final readonly class MonthlyCostPoint
{
    public function __construct(
        /** Y-m */
        public string $key,
        /** Short English month name: Jan … Dec */
        public string $month,
        public int $partsCents,
        public int $laborCents,
        public int $totalCents,
        public int $preventive,
        public int $corrective,
    ) {}
}
