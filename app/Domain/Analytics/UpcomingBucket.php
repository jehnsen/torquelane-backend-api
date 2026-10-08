<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

final readonly class UpcomingBucket
{
    public function __construct(
        /** "This week", "Week 2", … */
        public string $label,
        /** "08 Oct – 15 Oct" */
        public string $range,
        public int $overdue,
        public int $dueSoon,
        public int $upcoming,
    ) {}
}
