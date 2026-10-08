<?php

declare(strict_types=1);

namespace App\Domain\Shop;

final readonly class FloorUtilisation
{
    /**
     * @param  list<BayLoad>  $loads
     */
    public function __construct(
        public float|int $bookedHours,
        public float|int $capacityHours,
        public float|int $utilisation,
        public array $loads,
    ) {}
}
