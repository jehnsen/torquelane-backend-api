<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;
use DateTimeImmutable;

/**
 * Projects one maintenance item onto the calendar, for any asset with meters.
 * Generalises ../web/lib/interval-status.ts `computeIntervalStatus` (one km
 * meter + a calendar interval) to any number of meters; with exactly that
 * shape it gives the frontend's results bit for bit (golden-tested through
 * App\Domain\Fleet\IntervalStatus).
 *
 * The item falls due on whichever limit arrives FIRST. To compare a meter
 * limit with a calendar limit both are expressed as dates measured from the
 * same anchor, the last service: a meter limit becomes a date by dividing its
 * interval by the asset's average daily use. Measuring one forwards from the
 * last service and the other backwards from today would agree only while
 * both are ahead; once breached, min() over them would pick the LATER limit
 * and understate how long the asset has run past due.
 *
 * Ties go to the earlier-listed limit: meters in PlanItem order, then time.
 */
final class IntervalEngine
{
    /**
     * @param  array<string, MeterState>  $meters  the asset's meters, by MeterKind value
     */
    public static function evaluate(
        PlanItem $plan,
        LastService $last,
        array $meters,
        DueSoonThresholds $thresholds,
        DateTimeImmutable $today,
    ): IntervalOutcome {
        $calendarDueOn = $plan->calendarMonths === null
            ? null
            : Calendar::addMonths($last->doneOn, $plan->calendarMonths);

        $projections = [];
        foreach ($plan->meterIntervals as $kind => $interval) {
            $meter = $meters[$kind] ?? null;
            if ($meter === null) {
                continue;
            }
            $projections[$kind] = self::project($meter, $interval, $last->meterValues[$kind] ?? 0, $last->doneOn, $plan->calendarMonths, $today);
        }

        // Earliest limit governs; ties to the earlier-listed one.
        $governedBy = null;
        $projectedDue = null;
        foreach ($projections as $kind => $projection) {
            if ($projection->dueOn !== null && ($projectedDue === null || $projection->dueOn < $projectedDue)) {
                [$governedBy, $projectedDue] = [$kind, $projection->dueOn];
            }
        }
        if ($calendarDueOn !== null && ($projectedDue === null || $calendarDueOn < $projectedDue)) {
            [$governedBy, $projectedDue] = [IntervalOutcome::TIME, $calendarDueOn];
        }

        $daysRemaining = $projectedDue === null ? null : Calendar::differenceInCalendarDays($projectedDue, $today);

        $past = $daysRemaining !== null && $daysRemaining < 0;
        $soon = $daysRemaining !== null && $daysRemaining <= $thresholds->days;
        foreach ($projections as $kind => $projection) {
            $past = $past || $projection->remaining < 0;
            $soon = $soon || $projection->remaining <= ($thresholds->meterAmounts[$kind] ?? 0);
        }

        $progress = [0];
        foreach ($projections as $projection) {
            $progress[] = $projection->progress;
        }
        if ($calendarDueOn !== null) {
            $elapsedDays = Calendar::differenceInCalendarDays($today, $last->doneOn);
            $intervalDays = Calendar::differenceInCalendarDays($calendarDueOn, $last->doneOn);
            $progress[] = $intervalDays > 0 ? $elapsedDays / $intervalDays : 0;
        }

        return new IntervalOutcome(
            $projections,
            $calendarDueOn,
            $governedBy,
            $projectedDue,
            $past ? IntervalBand::Overdue : ($soon ? IntervalBand::DueSoon : IntervalBand::OnSchedule),
            $daysRemaining,
            max($progress),
        );
    }

    private static function project(
        MeterState $meter,
        float|int $interval,
        float|int $lastValue,
        DateTimeImmutable $doneOn,
        ?int $calendarMonths,
        DateTimeImmutable $today,
    ): MeterProjection {
        $dueAt = $lastValue + $interval;

        // A meter that is not moving never reaches its limit: the calendar
        // limit governs by default rather than dividing by zero.
        $rate = $meter->dailyRate > 0 ? $meter->dailyRate : 0;
        if ($rate > 0) {
            $dueOn = Calendar::addDays($doneOn, (int) JsMath::round($interval / $rate));
        } else {
            // Far enough out that the calendar limit always wins the comparison.
            $dueOn = $calendarMonths === null ? null : Calendar::addMonths($doneOn, $calendarMonths * 100);
        }

        // Readings are not always taken today: roll the meter forward so limits
        // are compared with an estimate of where the asset actually is.
        $daysSinceReading = max(0, Calendar::differenceInCalendarDays($today, $meter->readOn));
        $estimatedNow = JsMath::round($meter->value + $daysSinceReading * $rate);

        return new MeterProjection(
            $meter->kind,
            $dueAt,
            $dueOn,
            $estimatedNow,
            $dueAt - $estimatedNow,
            $interval > 0 ? ($estimatedNow - $lastValue) / $interval : 0,
        );
    }
}
