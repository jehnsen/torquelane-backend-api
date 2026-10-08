<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;

/**
 * A stored decimal as the number the ported (JavaScript-shaped) rules take:
 * an int when it has no fraction, else a float. Only for engine inputs
 * (meters, intervals); money never goes through here (R6).
 */
final class Num
{
    public static function of(BigDecimal $value): int|float
    {
        $stripped = $value->strippedOfTrailingZeros();

        return $stripped->getScale() === 0 ? $stripped->getIntegralPart()->toInt() : $stripped->toFloat();
    }
}
