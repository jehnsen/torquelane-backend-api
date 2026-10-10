<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

use App\Domain\Shared\Calendar;

/**
 * Sequential day-fill: a booking is a budget of 30-minute slots that fills
 * working days one after another. A port of the source's `planBookingDays`.
 *
 *  - Day 1 starts at the slot nearest the chosen time and runs to closing.
 *  - What is left rolls to the next calendar day, starting at opening.
 *  - The last day may be partial: only the slots needed are taken.
 *  - A start at or after closing takes nothing on day 1 and begins the next
 *    morning; the job is never refused for running late.
 *
 * Every calendar day counts as a working day, Sundays included, as in the
 * source. The lunch hour is not on the grid, so no plan ever holds it.
 */
final class DayPlanner
{
    /** The source's guard against a runaway loop: at most this many passes. */
    public const int MAX_PASSES = 60;

    /**
     * @param  string  $startDate  business date, Y-m-d
     * @param  int  $startMinutes  time of day chosen, minutes since midnight (need not be on the grid)
     * @param  int  $slotBudget  30-minute slots of work (`ServiceDuration::slotBudget`)
     */
    public static function plan(string $startDate, int $startMinutes, int $slotBudget, int $closingMinutes = SlotGrid::DEFAULT_CLOSING_MINUTES): DayPlan
    {
        if ($slotBudget <= 0 || $startMinutes < 0) {
            return new DayPlan([]);
        }

        $working = SlotGrid::workingSlots($closingMinutes);
        $firstSlot = SlotGrid::nearest($startMinutes);

        $days = [];
        $remaining = $slotBudget;
        $cursor = Calendar::parseDate($startDate);
        $isFirstDay = true;
        $passes = self::MAX_PASSES;

        while ($remaining > 0 && $passes-- > 0) {
            $available = $isFirstDay
                ? array_values(array_filter($working, fn (int $slot): bool => $slot >= $firstSlot))
                : $working;
            $taken = array_slice($available, 0, $remaining);

            if ($taken === []) {
                if ($isFirstDay) {
                    // Nothing left of day 1 (a start at or after closing): begin tomorrow.
                    $isFirstDay = false;
                    $cursor = Calendar::addDays($cursor, 1);

                    continue;
                }
                break;
            }

            $days[] = new PlannedDay(Calendar::toDate($cursor), $taken);
            $remaining -= count($taken);
            $isFirstDay = false;
            if ($remaining > 0) {
                $cursor = Calendar::addDays($cursor, 1);
            }
        }

        return new DayPlan($days);
    }
}
