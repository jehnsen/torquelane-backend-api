<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * An item is bought in its purchase unit (a case of 24) and kept in its stock
 * unit (each). `factor` is how many stock units one purchase unit holds.
 */
final class PurchaseUnit
{
    /** Purchase quantity → stock quantity (exact). */
    public static function toStockQuantity(string|BigDecimal $purchaseQuantity, string|BigDecimal $factor): BigDecimal
    {
        return BigDecimal::of($purchaseQuantity)->multipliedBy(self::factor($factor));
    }

    /** A purchase unit's price → one stock unit's cost, rounded half-up to a centavo. */
    public static function unitCostCents(int $purchaseUnitCostCents, string|BigDecimal $factor): int
    {
        return BigDecimal::of($purchaseUnitCostCents)->dividedBy(self::factor($factor), 0, RoundingMode::HalfUp)->toInt();
    }

    /** Stock quantity → whole purchase units that cover it (rounded up). */
    public static function purchaseUnitsFor(string|BigDecimal $stockQuantity, string|BigDecimal $factor): BigDecimal
    {
        return BigDecimal::of($stockQuantity)->dividedBy(self::factor($factor), 3, RoundingMode::Up);
    }

    private static function factor(string|BigDecimal $factor): BigDecimal
    {
        $factor = BigDecimal::of($factor);
        if (! $factor->isPositive()) {
            throw new InvalidArgumentException('A purchase unit holds a positive number of stock units.');
        }

        return $factor;
    }
}
