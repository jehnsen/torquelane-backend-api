<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\WorkOrders\WorkOrderFacts;

/**
 * One bay's booked hours on a day. Nothing stops two jobs sharing a bay or a
 * job outside the bay's focus: utilisation simply reads over 100%.
 */
final readonly class BayLoad
{
    /**
     * @param  list<WorkOrderFacts>  $jobs
     */
    public function __construct(
        public string $bayId,
        public string $name,
        public float|int $bookedHours,
        public float|int $capacityHours,
        public float|int $utilisation,
        public array $jobs,
    ) {}
}
