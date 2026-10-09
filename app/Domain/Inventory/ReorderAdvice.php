<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/** One row of the Reorder view: where an item stands, and what to buy. */
final readonly class ReorderAdvice
{
    public const string STOCKOUT = 'stockout';

    public const string BELOW_REORDER_POINT = 'below_reorder_point';

    public const string FORECAST_SHORTFALL = 'forecast_shortfall';

    public const string OK = 'ok';

    public function __construct(
        public string $reason,
        /** onHand + onOrder − forecastDemand: where stock is heading over the horizon. */
        public BigDecimal $projected,
        /** In stock units; zero when nothing needs buying. */
        public BigDecimal $suggestedStockQuantity,
        /** The same, in whole-ish purchase units (rounded up to three decimals). */
        public BigDecimal $suggestedPurchaseQuantity,
    ) {}

    public function needsOrder(): bool
    {
        return $this->suggestedStockQuantity->isPositive();
    }
}
