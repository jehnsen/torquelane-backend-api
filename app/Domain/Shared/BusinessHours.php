<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeInterface;

/**
 * Port of ../web/lib/approvals.ts `businessHoursBetween`: working hours
 * (Mon–Fri 08:00–18:00 Manila, no holidays) between two instants, to one
 * decimal. A quote sent Friday evening does not read as three days late by
 * Monday morning.
 */
final class BusinessHours
{
    public const int START_HOUR = 8;

    public const int END_HOUR = 18;

    public static function between(DateTimeInterface $from, DateTimeInterface $to): float|int
    {
        $from = Calendar::local($from);
        $to = Calendar::local($to);
        if ($to <= $from) {
            return 0;
        }

        $hours = 0.0;
        $cursor = Calendar::startOfDay($from);
        $lastDay = Calendar::startOfDay($to);

        while ($cursor <= $lastDay) {
            if (! Calendar::isWeekend($cursor)) {
                $dayStart = $cursor->setTime(self::START_HOUR, 0);
                $dayEnd = $cursor->setTime(self::END_HOUR, 0);

                $windowStart = Calendar::isSameDay($cursor, $from) && $from > $dayStart ? $from : $dayStart;
                $windowEnd = Calendar::isSameDay($cursor, $to) && $to < $dayEnd ? $to : $dayEnd;

                $overlapMs = max(0, self::ms($windowEnd) - self::ms($windowStart));
                $hours += $overlapMs / 3_600_000;
            }
            $cursor = Calendar::addDays($cursor, 1);
        }

        return JsMath::round($hours * 10) / 10;
    }

    private static function ms(DateTimeInterface $instant): int
    {
        return (int) $instant->format('Uv');
    }
}
