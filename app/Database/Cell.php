<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Reading a value out of a raw database row (a query-builder aggregate, a
 * plucked column) without trusting its type: a number comes out an int, text
 * a string, anything else nothing.
 */
final class Cell
{
    public static function string(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => '',
        };
    }

    public static function int(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) => (int) $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => 0,
        };
    }

    /** A date column as `YYYY-MM-DD` (the first ten characters of its text). */
    public static function date(mixed $value): string
    {
        return substr(self::string($value), 0, 10);
    }
}
