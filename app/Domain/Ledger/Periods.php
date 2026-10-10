<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use DateTimeImmutable;
use InvalidArgumentException;

/** Monthly accounting periods, by Asia/Manila calendar month. */
final class Periods
{
    /** `2026-10` for any business date in October 2026. */
    public static function keyOf(string $date): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $m) !== 1) {
            throw new InvalidArgumentException("[{$date}] is not a business date.");
        }

        return $m[1].'-'.$m[2];
    }

    /**
     * @return array{starts_on: string, ends_on: string}
     */
    public static function bounds(string $key): array
    {
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $key.'-01');
        if ($first === false || $first->format('Y-m') !== $key) {
            throw new InvalidArgumentException("[{$key}] is not a period key.");
        }

        return ['starts_on' => $first->format('Y-m-d'), 'ends_on' => $first->format('Y-m-t')];
    }

    public static function label(string $key): string
    {
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $key.'-01');

        return $first === false ? $key : $first->format('F Y');
    }
}
