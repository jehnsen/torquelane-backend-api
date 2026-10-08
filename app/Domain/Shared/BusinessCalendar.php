<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Business dates are Asia/Manila; timestamps are UTC (R9).
 *
 * A UTC timestamp's own calendar date is wrong for eight hours of every
 * Manila day: a job closed at 07:30 on the 9th in Manila is 23:30 on the 8th
 * in UTC. Every business date (`date` column) is derived here, never by
 * formatting a timestamp directly.
 */
final class BusinessCalendar
{
    public const TIMEZONE = 'Asia/Manila';

    /** The Manila calendar date (Y-m-d) on which an instant falls. */
    public static function dateOf(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(self::timezone())
            ->format('Y-m-d');
    }

    /** The UTC instant at which a Manila business date begins. */
    public static function startOfDayUtc(string $date): DateTimeImmutable
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::timezone());

        if ($start === false || $start->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("[{$date}] is not a Y-m-d date.");
        }

        return $start->setTimezone(new DateTimeZone('UTC'));
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }
}
