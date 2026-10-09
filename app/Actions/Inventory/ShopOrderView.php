<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\ShopPurchaseOrder;
use Brick\Math\BigDecimal;

/** A shop purchase order with what its posted receipts have taken in per line (purchase unit). */
final readonly class ShopOrderView
{
    /**
     * @param  array<string, BigDecimal>  $received  line id → quantity received
     */
    public function __construct(
        public ShopPurchaseOrder $order,
        public array $received,
    ) {}
}
