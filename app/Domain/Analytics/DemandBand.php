<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

final readonly class DemandBand
{
    /**
     * @param  list<UrgentItem>  $items
     */
    public function __construct(
        public array $items,
        /** Service items in the band. */
        public int $count,
        /** Distinct vehicles those items belong to. */
        public int $vehicleCount,
        /** Catalogue cost of clearing the band. */
        public int $estimatedCostCents,
    ) {}
}
