<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Shared\Calendar;

/**
 * Current reading and average daily use, DERIVED from the append-only
 * readings (the frontend stored both on the vehicle).
 *
 *  - Current: the latest reading by date, then by id (insertion order).
 *  - Rate: (current − baseline) / calendar days between them, where the
 *    baseline is the earliest reading inside the trailing window
 *    (WINDOW_DAYS before the current one), or, when the window holds none,
 *    the most recent reading before it. Fewer than two readings on
 *    different days: rate 0, so the calendar limit governs (the engine's
 *    stationary-asset rule).
 *
 * A trailing window keeps the rate current (a vehicle reassigned to a
 * longer route shows it within weeks) without letting one noisy pair of
 * same-week readings swing it.
 */
final class MeterRate
{
    public const int WINDOW_DAYS = 90;

    /**
     * @param  list<MeterReading>  $readings  effective readings, any order
     */
    public static function current(array $readings): ?MeterReading
    {
        $sorted = self::sorted($readings);

        return $sorted === [] ? null : $sorted[count($sorted) - 1];
    }

    /**
     * @param  list<MeterReading>  $readings
     */
    public static function dailyRate(array $readings): float|int
    {
        $sorted = self::sorted($readings);
        $current = $sorted === [] ? null : $sorted[count($sorted) - 1];
        if ($current === null) {
            return 0;
        }

        $currentOn = Calendar::parseDate($current->readOn);
        $windowStart = Calendar::addDays($currentOn, -self::WINDOW_DAYS);

        $baseline = null;
        $beforeWindow = null;
        foreach ($sorted as $reading) {
            $on = Calendar::parseDate($reading->readOn);
            if ($on >= $currentOn) {
                break;
            }
            if ($on >= $windowStart) {
                $baseline = $reading;
                break;
            }
            $beforeWindow = $reading;
        }
        $baseline ??= $beforeWindow;
        if ($baseline === null) {
            return 0;
        }

        $days = Calendar::differenceInCalendarDays($currentOn, Calendar::parseDate($baseline->readOn));

        return $days > 0 ? max(0, $current->value - $baseline->value) / $days : 0;
    }

    /**
     * @param  list<MeterReading>  $readings
     * @return list<MeterReading>
     */
    private static function sorted(array $readings): array
    {
        usort($readings, fn (MeterReading $a, MeterReading $b): int => [$a->readOn, $a->id] <=> [$b->readOn, $b->id]);

        return $readings;
    }
}
