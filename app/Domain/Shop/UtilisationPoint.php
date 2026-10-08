<?php

declare(strict_types=1);

namespace App\Domain\Shop;

final readonly class UtilisationPoint
{
    public function __construct(
        public string $key,
        public string $label,
        /** Whole percent. */
        public int|float $utilisation,
        public float|int $bookedHours,
    ) {}
}
