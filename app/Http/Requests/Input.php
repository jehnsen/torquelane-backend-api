<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Reads a validated request value of a known shape out of `mixed`. Validation
 * has already refused the wrong shapes; these keep the types honest for the
 * code after it, and fall back to nothing rather than a guess.
 */
final class Input
{
    /** A string (numbers are written out; anything else is empty). */
    public static function string(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }

    /** A lower-cased ULID (ULIDs are stored lower-case). */
    public static function id(mixed $value): string
    {
        return strtolower(self::string($value));
    }

    public static function int(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) || is_float($value) ? (int) $value : 0;
    }

    /** A non-empty trimmed string, else null. */
    public static function text(mixed $value): ?string
    {
        $text = trim(self::string($value));

        return $text === '' ? null : $text;
    }

    /**
     * An array of arrays (the rows of a validated list); anything else is nothing.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(mixed $value): array
    {
        $rows = [];
        if (is_array($value)) {
            foreach ($value as $row) {
                $rows[] = is_array($row) ? $row : [];
            }
        }

        return $rows;
    }
}
