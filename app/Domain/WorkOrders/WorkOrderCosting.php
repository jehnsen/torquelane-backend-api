<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Port of ../web/lib/pms.ts `resolvePartsCost` / `workOrderCost`, in exact
 * decimals rather than JavaScript floats (R6): parts cost prefers the
 * itemised lines once a technician has recorded any; estimates carry only the
 * aggregate. Rounded to centavos once, on the total (golden-tested against
 * pms.json in integer centavos).
 *
 * Inputs are decimal strings (or ints), never floats.
 */
final class WorkOrderCosting
{
    /**
     * @param  list<array{quantity: string|int, unitCost: string|int}>|null  $parts
     */
    public static function partsCost(?array $parts, string|int $aggregatePartsCost): Money
    {
        if ($parts === null || $parts === []) {
            return self::money(BigDecimal::of($aggregatePartsCost));
        }

        $total = BigDecimal::zero();
        foreach ($parts as $part) {
            $total = $total->plus(BigDecimal::of($part['quantity'])->multipliedBy($part['unitCost']));
        }

        return self::money($total);
    }

    /**
     * @param  list<array{quantity: string|int, unitCost: string|int}>|null  $parts
     */
    public static function totalCost(string|int $laborCost, ?array $parts, string|int $aggregatePartsCost): Money
    {
        $parts = $parts === null || $parts === []
            ? BigDecimal::of($aggregatePartsCost)
            : array_reduce($parts, fn (BigDecimal $sum, array $part): BigDecimal => $sum->plus(BigDecimal::of($part['quantity'])->multipliedBy($part['unitCost'])), BigDecimal::zero());

        return self::money(BigDecimal::of($laborCost)->plus($parts));
    }

    private static function money(BigDecimal $amount): Money
    {
        return Money::of($amount->toScale(2, RoundingMode::HalfUp), 'PHP');
    }
}
