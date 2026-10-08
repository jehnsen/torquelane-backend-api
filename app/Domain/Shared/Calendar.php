<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Calendar arithmetic in Asia/Manila, matching the date-fns functions the
 * frontend's engine was written with, so ported rules give identical dates:
 *
 *  - `addMonths` keeps the day of month and time of day, clamping to the last
 *    day of a shorter month (31 Jan + 1 month = 28/29 Feb), as date-fns does;
 *  - `addDays` moves the local calendar date, keeping the time of day;
 *  - `differenceInCalendarDays` counts local calendar days, ignoring time;
 *  - `parseDate` reads a `Y-m-d` business date as local midnight, as
 *    date-fns `parseISO` does for a date-only string.
 *
 * Every result is in Manila time, whatever zone the input carried.
 */
final class Calendar
{
    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(BusinessCalendar::TIMEZONE);
    }

    public static function local(DateTimeInterface $instant): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(self::zone());
    }

    /** `Y-m-d` → local midnight. */
    public static function parseDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::zone());
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("[{$date}] is not a Y-m-d date.");
        }

        return $parsed;
    }

    /** The local calendar date (`formatISO(d, { representation: "date" })`). */
    public static function toDate(DateTimeInterface $instant): string
    {
        return self::local($instant)->format('Y-m-d');
    }

    public static function addDays(DateTimeInterface $instant, int $days): DateTimeImmutable
    {
        $local = self::local($instant);

        return $days === 0 ? $local : $local->modify(sprintf('%+d days', $days));
    }

    public static function addMonths(DateTimeInterface $instant, int $months): DateTimeImmutable
    {
        $local = self::local($instant);
        if ($months === 0) {
            return $local;
        }

        $year = (int) $local->format('Y');
        $month = (int) $local->format('n') - 1 + $months;
        $year += intdiv($month, 12) - ($month % 12 < 0 ? 1 : 0);
        $month = (($month % 12) + 12) % 12 + 1;

        $daysInMonth = (int) $local->setDate($year, $month, 1)->format('t');
        $day = min((int) $local->format('j'), $daysInMonth);

        return $local->setDate($year, $month, $day);
    }

    /** Local calendar days from $earlier to $later (negative when $later is earlier). */
    public static function differenceInCalendarDays(DateTimeInterface $later, DateTimeInterface $earlier): int
    {
        $utc = new DateTimeZone('UTC');
        $a = new DateTimeImmutable(self::toDate($later), $utc);
        $b = new DateTimeImmutable(self::toDate($earlier), $utc);

        return intdiv($a->getTimestamp() - $b->getTimestamp(), 86400);
    }

    public static function startOfDay(DateTimeInterface $instant): DateTimeImmutable
    {
        return self::local($instant)->setTime(0, 0);
    }

    public static function isWeekend(DateTimeInterface $instant): bool
    {
        return (int) self::local($instant)->format('N') >= 6;
    }

    public static function isSameDay(DateTimeInterface $a, DateTimeInterface $b): bool
    {
        return self::toDate($a) === self::toDate($b);
    }
}
