<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrders;

final readonly class DraftPurchaseOrder
{
    /**
     * @param  list<DraftPurchaseLine>  $lines
     */
    public function __construct(
        public string $vendor,
        public array $lines,
    ) {}
}
