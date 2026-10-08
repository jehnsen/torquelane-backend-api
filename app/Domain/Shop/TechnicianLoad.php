<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\WorkOrders\WorkOrderFacts;

final readonly class TechnicianLoad
{
    public function __construct(
        public string $technician,
        public ?WorkOrderFacts $current,
        public int $completedThisPeriod,
        public float|int|null $avgActualHours,
        public float|int|null $avgEstimatedHours,
    ) {}
}
