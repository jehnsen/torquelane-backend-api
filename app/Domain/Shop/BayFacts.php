<?php

declare(strict_types=1);

namespace App\Domain\Shop;

final readonly class BayFacts
{
    public function __construct(
        public string $id,
        public string $name,
        public float|int $capacityHoursPerDay,
    ) {}
}
