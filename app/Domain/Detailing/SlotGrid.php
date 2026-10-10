<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * The booking grid: 30-minute slots from opening to the grid's end, with the
 * lunch hour left out. Ported from the source's `src/data/timeSlots.js`
 * (8:00 AM – 9:00 PM, no 12:00 or 12:30 slot). A slot is named by its start, in
 * minutes since midnight in the branch's day.
 *
 * The grid runs later than the shop closes on purpose: closing is a branch
 * setting (`closing_minutes`, 5:00 PM by default) and only caps where a day's
 * work may START. A job that does not fit before closing rolls into the next
 * day (`DayPlanner`); it is never refused for running late.
 */
final class SlotGrid
{
    public const int SLOT_MINUTES = 30;

    /** First slot of the day (8:00 AM). */
    public const int OPENING_MINUTES = 480;

    /** The lunch hour: no slot starts in [12:00 PM, 1:00 PM). */
    public const int LUNCH_START_MINUTES = 720;

    public const int LUNCH_END_MINUTES = 780;

    /** Last slot the grid has (9:00 PM): the ceiling for a branch's closing time. */
    public const int GRID_END_MINUTES = 1260;

    /** The source's default closing cutoff (5:00 PM). */
    public const int DEFAULT_CLOSING_MINUTES = 1020;

    /**
     * Every slot start of a day, in order.
     *
     * @return list<int>
     */
    public static function slots(): array
    {
        $slots = [];
        for ($minutes = self::OPENING_MINUTES; $minutes <= self::GRID_END_MINUTES; $minutes += self::SLOT_MINUTES) {
            if ($minutes >= self::LUNCH_START_MINUTES && $minutes < self::LUNCH_END_MINUTES) {
                continue;
            }
            $slots[] = $minutes;
        }

        return $slots;
    }

    /**
     * The slots a working day offers: those starting before closing.
     *
     * @return list<int>
     */
    public static function workingSlots(int $closingMinutes): array
    {
        return array_values(array_filter(self::slots(), fn (int $slot): bool => $slot < $closingMinutes));
    }

    public static function isSlot(int $minutes): bool
    {
        return in_array($minutes, self::slots(), true);
    }

    /**
     * The grid slot a start time belongs to: the NEAREST slot, ties going
     * forward (10:44 → 10:30, 10:45 → 11:00, 12:15 PM → 1:00 PM across lunch).
     * Before the first slot clamps to it; at or past the last clamps to that.
     *
     * Nearest, not "at or before": flooring made 10:44 sit in the 10:30 bucket
     * and collide with a job already there (the source's `slotIndex`).
     */
    public static function nearest(int $minutes): int
    {
        $slots = self::slots();
        if (in_array($minutes, $slots, true)) {
            return $minutes;
        }
        $first = $slots[0];
        $last = $slots[count($slots) - 1];
        if ($minutes <= $first) {
            return $first;
        }
        if ($minutes >= $last) {
            return $last;
        }

        $best = $first;
        $bestDistance = PHP_INT_MAX;
        foreach ($slots as $slot) {
            $distance = abs($slot - $minutes);
            // `<=` lets a later, equidistant slot win.
            if ($distance <= $bestDistance) {
                $bestDistance = $distance;
                $best = $slot;
            }
        }

        return $best;
    }

    /** The 30-minute bucket a minute of the day falls in (9:45 → 9:30). */
    public static function bucketFloor(int $minutes): int
    {
        return intdiv($minutes, self::SLOT_MINUTES) * self::SLOT_MINUTES;
    }

    /** `"14:30"` (24-hour) → 870; null when it is not a time of day. */
    public static function parseTime(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) !== 1) {
            return null;
        }
        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        return $hours > 23 || $minutes > 59 ? null : $hours * 60 + $minutes;
    }

    /** `"2:30 PM"` → 870, as the source's `parseTimeToMinutes`; null when it does not parse. */
    public static function parseLabel(string $label): ?int
    {
        if (preg_match('/(\d+):(\d+)\s*(AM|PM)/i', $label, $m) !== 1) {
            return null;
        }
        $hours = (int) $m[1];
        $period = strtoupper($m[3]);
        if ($period === 'PM' && $hours !== 12) {
            $hours += 12;
        }
        if ($period === 'AM' && $hours === 12) {
            $hours = 0;
        }

        return $hours * 60 + (int) $m[2];
    }

    /** 870 → `"14:30"`. */
    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }

    /** 870 → `"2:30 PM"`, as the source's `minutesToTimeStr`. */
    public static function label(int $minutes): string
    {
        $hours24 = intdiv($minutes, 60) % 24;
        $hours12 = $hours24 % 12 === 0 ? 12 : $hours24 % 12;

        return sprintf('%d:%02d %s', $hours12, $minutes % 60, $hours24 >= 12 ? 'PM' : 'AM');
    }
}
