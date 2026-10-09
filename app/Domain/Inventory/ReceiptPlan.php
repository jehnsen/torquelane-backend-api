<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/** How much of a purchase order line can still be taken in. */
final class ReceiptPlan
{
    /** Ordered − received so far, never below zero (purchase units). */
    public static function remaining(string $ordered, string $received): BigDecimal
    {
        return BigDecimal::max(BigDecimal::of($ordered)->minus($received), BigDecimal::zero());
    }

    /** Whether receiving `$quantity` more stays within what was ordered. */
    public static function fits(string $ordered, string $received, string $quantity): bool
    {
        return BigDecimal::of($quantity)->isLessThanOrEqualTo(self::remaining($ordered, $received));
    }
}
