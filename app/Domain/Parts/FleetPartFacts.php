<?php

declare(strict_types=1);

namespace App\Domain\Parts;

/**
 * One spare part a customer account stocks for its own fleet (../web `Part`),
 * as the forecast reads it. Not shop inventory: stock is the customer's, held
 * per account, replenished by receiving the account's purchase orders.
 */
final readonly class FleetPartFacts
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
        /** A task category, or `other`. */
        public string $category,
        /** piece, litre, set, pair, service, … */
        public string $unit,
        public int $unitCostCents,
        public int $currentStock,
        public int $reorderPoint,
        public string $preferredVendor,
        public int $leadTimeDays,
    ) {}
}
