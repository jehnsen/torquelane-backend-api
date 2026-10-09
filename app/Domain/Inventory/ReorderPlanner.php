<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/**
 * The Reorder view's rule, combining what is on the shelf, what is already on
 * order, the branch's reorder point and quantity, and the fleet forecast's
 * demand for the item over its horizon.
 *
 *   projected = on hand + on order − forecast demand
 *
 *  - stockout            on hand is zero or less and something needs it
 *                        (a reorder point is set, or the forecast wants some);
 *  - below_reorder_point on hand + on order is under the reorder point;
 *  - forecast_shortfall  projected is under the reorder point (or under zero
 *                        where no point is set) because of forecast demand;
 *  - ok                  nothing to buy.
 *
 * When something is needed the suggestion is the larger of the reorder
 * quantity and the gap back up to the reorder point, in stock units, then
 * rounded up to purchase units.
 */
final class ReorderPlanner
{
    public static function advise(
        string $onHand,
        string $onOrder,
        string $forecastDemand,
        ?string $reorderPoint,
        ?string $reorderQuantity,
        string $purchaseFactor = '1',
    ): ReorderAdvice {
        $onHand = BigDecimal::of($onHand);
        $supply = $onHand->plus($onOrder);
        $projected = $supply->minus($forecastDemand);
        $point = $reorderPoint === null ? null : BigDecimal::of($reorderPoint);
        $demand = BigDecimal::of($forecastDemand)->isPositive();

        $floor = $point ?? BigDecimal::zero();
        $reason = match (true) {
            ! $onHand->isPositive() && ($point !== null || $demand) => ReorderAdvice::STOCKOUT,
            $point !== null && $supply->isLessThan($point) => ReorderAdvice::BELOW_REORDER_POINT,
            $projected->isLessThan($floor) && $demand => ReorderAdvice::FORECAST_SHORTFALL,
            default => ReorderAdvice::OK,
        };

        if ($reason === ReorderAdvice::OK) {
            return new ReorderAdvice($reason, $projected, BigDecimal::zero(), BigDecimal::zero());
        }

        // A stockout with the supply already covering it (a purchase order is on
        // its way) still has nothing further to buy.
        $gap = $floor->minus($projected);
        $suggested = BigDecimal::max($reorderQuantity === null ? BigDecimal::zero() : BigDecimal::of($reorderQuantity), $gap);
        if (! $suggested->isPositive() || ($reason === ReorderAdvice::STOCKOUT && ! $projected->isLessThan($floor))) {
            return new ReorderAdvice($reason, $projected, BigDecimal::zero(), BigDecimal::zero());
        }

        return new ReorderAdvice($reason, $projected, $suggested, PurchaseUnit::purchaseUnitsFor($suggested, $purchaseFactor));
    }
}
