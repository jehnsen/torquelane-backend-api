<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * How long a package takes, read from the free text the shop types
 * ("45 min", "1.5 hrs", "1 hr 30 min", "4–5 hrs", "1–2 days"). A port of the
 * source's `parseDurationMinutes` / `getSlotsConsumed` / `getDaysConsumed` /
 * `getTotalSlotBudget`, kept bit for bit so a package brought over from the
 * source blocks the same slots here:
 *
 *  - hours: "1 hr", "1 hour", "1.5 hrs", "2h", ".5 hrs";
 *  - minutes: "45 min", "45 mins", "45 minutes", "45m";
 *  - compound, summed: "1 hr 30 min", "1h30m"; clock form "1:30";
 *  - a range takes its UPPER bound: "2-3 hrs" → 3 hours, "1–2 days" → 2 days;
 *  - a lone number with no unit is HOURS ("2" → 2 hours);
 *  - a day is eight working hours, wherever the job starts.
 *
 * The grid is 30 minutes: anything shorter rounds UP (45 min → 2 slots), and
 * nothing is ever zero slots.
 */
final class ServiceDuration
{
    /** Hours of work one "day" of a day-based package stands for. */
    public const int HOURS_PER_SERVICE_DAY = 8;

    /** A number that may start with the decimal point: "2", "1.5", ".5". */
    private const string NUM = '\d*\.?\d+';

    /** Hour and minute durations, in minutes; 0 when nothing parses. */
    public static function minutes(string $duration): float
    {
        $d = strtolower(trim($duration));
        if ($d === '') {
            return 0.0;
        }

        if (preg_match('/^(\d+)\s*:\s*(\d{1,2})$/', $d, $clock) === 1) {
            return (float) ((int) $clock[1] * 60 + (int) $clock[2]);
        }

        $minuteToken = '/('.self::NUM.')\s*(?:mins?|minutes?|m)\b/u';
        $minutes = 0.0;
        $matchedMinutes = false;
        if (preg_match_all($minuteToken, $d, $found) > 0) {
            foreach ($found[1] as $number) {
                $minutes += (float) $number;
                $matchedMinutes = true;
            }
        }

        // Minute tokens are taken out first so their numbers are not re-read as hours.
        $hoursText = preg_replace($minuteToken, ' ', $d) ?? $d;
        $hours = 0.0;
        $matchedHours = false;
        if (preg_match('/('.self::NUM.')\s*[–-]\s*('.self::NUM.')\s*(?:h|hr|hrs|hour|hours)?\b/u', $hoursText, $range) === 1) {
            $hours = (float) $range[2];
            $matchedHours = true;
        } else {
            if (preg_match_all('/('.self::NUM.')\s*(?:h|hr|hrs|hour|hours)\b/u', $hoursText, $found) > 0) {
                foreach ($found[1] as $number) {
                    $hours += (float) $number;
                    $matchedHours = true;
                }
            }
            if (! $matchedHours && ! $matchedMinutes && preg_match('/('.self::NUM.')/', $hoursText, $bare) === 1) {
                $hours = (float) $bare[1];
            }
        }

        return $hours * 60 + $minutes;
    }

    /** Whole days a day-based duration names (upper bound); 0 for an hour-based one. */
    public static function days(string $duration): int
    {
        $d = strtolower($duration);
        if (! str_contains($d, 'day')) {
            return 0;
        }
        if (preg_match('/(\d+)\s*[–-]\s*(\d+)\s*day/u', $d, $range) === 1) {
            return (int) $range[2];
        }
        if (preg_match('/(\d+)\s*day/', $d, $single) === 1) {
            return (int) $single[1];
        }

        return 1;
    }

    /**
     * How many 30-minute slots of work the package is: the single measure of
     * "how much work", independent of when it starts.
     */
    public static function slotBudget(string $duration): int
    {
        $days = self::days($duration);
        if ($days > 0) {
            return max(1, $days * self::HOURS_PER_SERVICE_DAY * intdiv(60, SlotGrid::SLOT_MINUTES));
        }
        $minutes = self::minutes($duration);
        if ($minutes <= 0) {
            return 1;
        }

        return max(1, (int) ceil($minutes / SlotGrid::SLOT_MINUTES));
    }

    /** "Interpreted as …": what the shop is shown while typing a duration. */
    public static function describe(string $duration): string
    {
        $days = self::days($duration);
        $slots = self::slotBudget($duration);
        $worked = $slots * SlotGrid::SLOT_MINUTES;
        $hours = intdiv($worked, 60);
        $rest = $worked % 60;
        $time = trim(($hours > 0 ? "{$hours} hr".($hours === 1 ? '' : 's') : '').($rest > 0 ? " {$rest} min" : ''));

        return $days > 0
            ? sprintf('%d day%s of work (%s)', $days, $days === 1 ? '' : 's', $time)
            : $time;
    }
}
