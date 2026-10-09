<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/** A stock count's finding for one item: what the books said, what was on the shelf. */
final class CountVariance
{
    /** Counted − expected: positive means more was found than the books held. */
    public static function between(string $expected, string $counted): BigDecimal
    {
        return BigDecimal::of($counted)->minus($expected);
    }
}
