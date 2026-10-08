<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

use App\Domain\Shared\BusinessCalendar;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Series periods and the printed form of a document number.
 *
 * Series restart every Manila calendar year (R9): a document issued at
 * 07:30 on 1 January in Manila belongs to the new year even though it is
 * still 31 December in UTC.
 */
final class DocumentNumberFormat
{
    public const int DEFAULT_PADDING = 4;

    public static function periodKey(DateTimeInterface $issuedAt): string
    {
        return substr(BusinessCalendar::dateOf($issuedAt), 0, 4);
    }

    /** `WO-2026-0001`. Numbers past the padding simply grow (`WO-2026-10000`). */
    public static function format(string $prefix, string $periodKey, int $number, int $padding = self::DEFAULT_PADDING): string
    {
        if ($number < 1) {
            throw new InvalidArgumentException('Document numbers start at 1.');
        }

        return sprintf('%s-%s-%s', $prefix, $periodKey, str_pad((string) $number, $padding, '0', STR_PAD_LEFT));
    }
}
