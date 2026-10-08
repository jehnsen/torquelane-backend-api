<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * The few JavaScript numeric behaviours the ported engine depends on.
 * PHP's `round()` rounds halves away from zero; JavaScript's `Math.round`
 * rounds them towards +∞ (`Math.round(-2.5)` is -2). Every ported rule that
 * rounds goes through here, or its outputs drift from the frontend's.
 */
final class JsMath
{
    /** `Math.round`: nearest integer, halves towards +∞. */
    public static function round(float|int $value): int|float
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_nan($value) || is_infinite($value)) {
            return $value;
        }

        $floor = floor($value);
        $rounded = ($value - $floor) >= 0.5 ? $floor + 1 : $floor;

        return abs($rounded) < PHP_INT_MAX ? (int) $rounded : $rounded;
    }

    /** `String(n)` for a finite number: integers without a fraction, others shortest round-trip. */
    public static function toString(float|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
    }

    /** `Math.max`-style clamp. */
    public static function clamp(float|int $value, float|int $min, float|int $max): float|int
    {
        return min(max($value, $min), $max);
    }
}
