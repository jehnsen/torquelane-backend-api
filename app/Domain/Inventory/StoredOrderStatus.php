<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** What a shop purchase order stores of its status; the rest is derived (ShopOrderStatus). */
enum StoredOrderStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
}
