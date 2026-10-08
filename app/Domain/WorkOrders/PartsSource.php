<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/**
 * Who supplies a line's part: the shop buys it in (and earns its markup), or
 * it comes from the customer's own stock (no margin).
 */
enum PartsSource: string
{
    case OwnStock = 'own_stock';
    case SupplierProvided = 'supplier_provided';
}
