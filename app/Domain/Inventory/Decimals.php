<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/**
 * Reading a number out of something untyped (a database aggregate, a decoded
 * request) without trusting its type: anything that is not a number is
 * nothing, never a guess.
 */
final class Decimals
{
    public static function of(mixed $value): BigDecimal
    {
        return self::nullable($value) ?? BigDecimal::zero();
    }

    public static function nullable(mixed $value): ?BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        return (is_string($value) && is_numeric($value)) || is_int($value) ? BigDecimal::of($value) : null;
    }

    public static function int(mixed $value): int
    {
        return (is_string($value) && is_numeric($value)) || is_int($value) || is_float($value) ? (int) $value : 0;
    }
}
