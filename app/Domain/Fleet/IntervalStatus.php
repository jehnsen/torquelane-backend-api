<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Maintenance\IntervalEngine;
use App\Domain\Maintenance\IntervalOutcome;
use App\Domain\Maintenance\LastService;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Maintenance\MeterState;
use App\Domain\Maintenance\PlanItem;
use DateTimeImmutable;
use LogicException;

/**
 * Port of ../web/lib/interval-status.ts `computeIntervalStatus`: the vehicle
 * shape of the generic IntervalEngine (one km meter, one calendar interval).
 * Golden-tested against interval-status.json.
 */
final class IntervalStatus
{
    public static function compute(
        DateTimeImmutable $lastCompletedAt,
        float|int $lastCompletedOdometer,
        float|int $distanceIntervalKm,
        int $timeIntervalMonths,
        float|int $currentOdometer,
        DateTimeImmutable $currentOdometerReadAt,
        float|int $avgKmPerDay,
        DateTimeImmutable $today,
    ): IntervalStatusResult {
        $km = MeterKind::Km->value;

        $outcome = IntervalEngine::evaluate(
            new PlanItem([$km => $distanceIntervalKm], $timeIntervalMonths),
            new LastService($lastCompletedAt, [$km => $lastCompletedOdometer]),
            [$km => new MeterState(MeterKind::Km, $currentOdometer, $currentOdometerReadAt, $avgKmPerDay)],
            FleetThresholds::dueSoon(),
            $today,
        );

        $distance = $outcome->meter(MeterKind::Km);
        if ($distance === null || $distance->dueOn === null || $outcome->calendarDueOn === null
            || $outcome->projectedDue === null || $outcome->daysRemaining === null) {
            throw new LogicException('A km + calendar plan always projects both limits.');
        }

        return new IntervalStatusResult(
            $distance->dueAt,
            $distance->dueOn,
            $outcome->calendarDueOn,
            $outcome->governedBy === IntervalOutcome::TIME ? 'time' : 'distance',
            $outcome->projectedDue,
            $outcome->status,
            $distance->remaining < 0 ? -$distance->remaining : null,
            $outcome->daysRemaining < 0 ? -$outcome->daysRemaining : null,
            $distance->estimatedNow,
            $outcome->daysRemaining,
            $distance->remaining,
            $outcome->progress,
        );
    }
}
