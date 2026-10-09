<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * The ledger's verdict on one move: the balance afterwards, the unit cost the
 * move is recorded at, and whether it left the balance negative.
 */
final readonly class MoveOutcome
{
    public function __construct(
        public StockState $after,
        public int $unitCostCents,
        public bool $negative,
    ) {}
}
