<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/** One balance: quantity on hand (3 decimals) and its weighted-average unit cost in centavos. */
final readonly class StockState
{
    public BigDecimal $onHand;

    public function __construct(string|BigDecimal $onHand, public int $avgCostCents)
    {
        $this->onHand = BigDecimal::of($onHand);
    }

    public static function empty(): self
    {
        return new self('0', 0);
    }
}
