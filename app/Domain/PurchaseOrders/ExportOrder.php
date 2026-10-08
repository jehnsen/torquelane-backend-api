<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrders;

final readonly class ExportOrder
{
    /**
     * @param  list<array{description: string, quantity: int, unit_cost_cents: int}>  $lines
     */
    public function __construct(
        public string $reference,
        public string $vendor,
        public PurchaseOrderStatus $status,
        /** Y-m-d */
        public string $createdOn,
        public string $createdBy,
        public array $lines,
    ) {}
}
