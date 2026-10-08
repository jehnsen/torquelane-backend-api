<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/** A part fitted at close-out. */
final readonly class PartFacts
{
    public function __construct(
        /** Decimal string. */
        public string $quantity,
        public int $unitCostCents,
    ) {}
}
