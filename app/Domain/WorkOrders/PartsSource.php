<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/**
 * Where a line's part comes from, per line (the hybrid model).
 *
 * Phase 3's two values keep their meaning and no stock moves for them:
 *  - own_stock          the part is charged, and earns no markup ("the client's
 *                       own stock", Shop::partsMargin);
 *  - supplier_provided  the shop buys it in, charged, earning its markup.
 *
 * Phase 6 adds three, which say what the inventory does:
 *  - customer_supplied  the customer brings the part: no stock move, NO part charge;
 *  - shop_stock         issued from the branch's inventory: a stock move out of
 *                       the branch store, and the part is charged;
 *  - purchased_for_job  bought on a shop purchase order for this job: the part is
 *                       charged, its cost is the goods received, and it never
 *                       goes through the shelf.
 */
enum PartsSource: string
{
    case OwnStock = 'own_stock';
    case SupplierProvided = 'supplier_provided';
    case CustomerSupplied = 'customer_supplied';
    case ShopStock = 'shop_stock';
    case PurchasedForJob = 'purchased_for_job';

    /** Whether the part is on the bill. */
    public function charged(): bool
    {
        return $this !== self::CustomerSupplied;
    }

    /** Whether the line names an inventory item and moves stock. */
    public function movesStock(): bool
    {
        return $this === self::ShopStock;
    }

    /** The two Phase 3 values: still valid, no longer offered for new lines. */
    public function isLegacy(): bool
    {
        return $this === self::OwnStock || $this === self::SupplierProvided;
    }
}
