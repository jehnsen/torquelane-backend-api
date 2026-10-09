<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * What a caller asks the ledger to record. `quantity` is signed. `unitCostCents`
 * is what inbound goods cost; outbound moves always leave at the average, so
 * a cost given for one is ignored.
 */
final readonly class MoveRequest
{
    public BigDecimal $quantity;

    public function __construct(
        public MoveType $type,
        string|BigDecimal $quantity,
        public ?int $unitCostCents = null,
    ) {
        $quantity = BigDecimal::of($quantity);
        if ($quantity->isZero()) {
            throw new InvalidArgumentException('A stock move moves something.');
        }
        if ($quantity->strippedOfTrailingZeros()->getScale() > 3) {
            throw new InvalidArgumentException('Quantities hold three decimals.');
        }
        $direction = $type->direction();
        if ($direction !== 0 && $quantity->getSign() !== $direction) {
            throw new InvalidArgumentException(sprintf('A %s move is %s.', $type->value, $direction > 0 ? 'inbound (positive)' : 'outbound (negative)'));
        }
        if ($unitCostCents !== null && $unitCostCents < 0) {
            throw new InvalidArgumentException('A unit cost cannot be negative.');
        }
        $this->quantity = $quantity;
    }

    public function isInbound(): bool
    {
        return $this->quantity->isPositive();
    }
}
