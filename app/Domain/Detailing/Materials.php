<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A package's bill of materials. Each line says what a MEDIUM vehicle uses;
 * a line that scales with size is multiplied by the branch's factor for the
 * vehicle's size class, rounded half-up to the three decimals stock is kept
 * in and never to nothing (a job uses at least a thousandth of a unit).
 */
final class Materials
{
    /**
     * @param  string  $baseQuantity  what a medium vehicle uses
     * @param  string  $multiplier  the branch's factor for the size class ("1.250")
     * @return string the quantity to take from stock, unsigned
     */
    public static function quantityFor(string $baseQuantity, bool $scalesWithSize, string $multiplier): string
    {
        $quantity = BigDecimal::of($baseQuantity);
        if ($scalesWithSize) {
            $quantity = $quantity->multipliedBy(BigDecimal::of($multiplier));
        }
        $quantity = $quantity->toScale(3, RoundingMode::HalfUp);
        if (! $quantity->isPositive()) {
            $quantity = BigDecimal::of('0.001');
        }

        return (string) $quantity->strippedOfTrailingZeros();
    }

    /**
     * What a job made: its price and extras less what its materials cost.
     *
     * @return array{revenue_cents: int, materials_cost_cents: int, margin_cents: int, margin_pct: string|null}
     */
    public static function margin(int $revenueCents, int $materialsCostCents): array
    {
        $margin = $revenueCents - $materialsCostCents;

        return [
            'revenue_cents' => $revenueCents,
            'materials_cost_cents' => $materialsCostCents,
            'margin_cents' => $margin,
            'margin_pct' => $revenueCents > 0
                ? (string) BigDecimal::of($margin)->multipliedBy(100)->dividedBy($revenueCents, 1, RoundingMode::HalfUp)
                : null,
        ];
    }
}
