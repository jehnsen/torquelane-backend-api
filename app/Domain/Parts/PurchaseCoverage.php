<?php

declare(strict_types=1);

namespace App\Domain\Parts;

use App\Domain\PurchaseOrders\PurchaseOrderStatus;

/**
 * A purchase order as the forecast reads it: each line records the due items
 * (task × vehicle) it was raised to cover.
 */
final readonly class PurchaseCoverage
{
    /**
     * @param  list<array{taskIds: list<string>, vehicleIds: list<string>}>  $lines
     */
    public function __construct(
        public PurchaseOrderStatus $status,
        public array $lines,
    ) {}
}
