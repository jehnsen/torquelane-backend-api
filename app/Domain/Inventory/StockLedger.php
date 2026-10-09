<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The maths of a stock balance, as plain PHP (R4). It never touches storage:
 * App\Actions\Inventory\PostStockMove locks the balance row, asks this what
 * the move does, and writes the move and the new balance together.
 *
 * Moving weighted average:
 *  - inbound goods at cost c join the stock at the average
 *    (on hand × average + qty × c) ÷ (on hand + qty), rounded half-up to a
 *    centavo. Onto an empty or negative balance there is nothing to blend
 *    with, so the average becomes c (when the balance ends above zero) or
 *    stays (when it is still short);
 *  - outbound goods leave at the current average, which does not change.
 *
 * Valuation is on hand × average. A total is summed exactly and rounded
 * once (R6), never summed from rounded rows.
 */
final class StockLedger
{
    public static function applyMove(StockState $before, MoveRequest $move, NegativeStockPolicy $policy = NegativeStockPolicy::AllowAndFlag): MoveOutcome
    {
        $after = $before->onHand->plus($move->quantity);

        if ($move->isInbound()) {
            $unit = $move->unitCostCents ?? ($move->type->requiresCost() ? throw new InvalidArgumentException("A {$move->type->value} move must say what the goods cost.") : $before->avgCostCents);

            return new MoveOutcome(new StockState($after, self::blend($before, $move->quantity, $unit)), $unit, false);
        }

        if ($policy === NegativeStockPolicy::Block && $after->isNegative()) {
            throw new InsufficientStock((string) $before->onHand->strippedOfTrailingZeros(), (string) $move->quantity->abs()->strippedOfTrailingZeros());
        }

        return new MoveOutcome(new StockState($after, $before->avgCostCents), $before->avgCostCents, $after->isNegative());
    }

    /** What the quantity on hand is worth, exactly (centavos, not yet rounded). */
    public static function exactValue(StockState $state): BigDecimal
    {
        return $state->onHand->multipliedBy($state->avgCostCents);
    }

    /** One balance's value, rounded half-up to a centavo. */
    public static function valuation(StockState $state): int
    {
        return self::roundCents(self::exactValue($state));
    }

    /**
     * A total over many balances: summed exactly, rounded once.
     *
     * @param  iterable<StockState>  $states
     */
    public static function totalValuation(iterable $states): int
    {
        $sum = BigDecimal::zero();
        foreach ($states as $state) {
            $sum = $sum->plus(self::exactValue($state));
        }

        return self::roundCents($sum);
    }

    /** A move's value: quantity × the unit cost it was recorded at (always positive). */
    public static function moveValue(string|BigDecimal $quantity, int $unitCostCents): int
    {
        return self::roundCents(BigDecimal::of($quantity)->abs()->multipliedBy($unitCostCents));
    }

    public static function roundCents(BigDecimal $centavos): int
    {
        return $centavos->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    private static function blend(StockState $before, BigDecimal $quantity, int $unitCostCents): int
    {
        if (! $before->onHand->isPositive()) {
            return $before->onHand->plus($quantity)->isPositive() ? $unitCostCents : $before->avgCostCents;
        }

        $total = $before->onHand->multipliedBy($before->avgCostCents)->plus($quantity->multipliedBy($unitCostCents));

        return $total->dividedBy($before->onHand->plus($quantity), 0, RoundingMode::HalfUp)->toInt();
    }
}
