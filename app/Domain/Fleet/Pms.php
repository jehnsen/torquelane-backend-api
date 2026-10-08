<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Maintenance\IntervalBand;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;
use DateTimeImmutable;

/**
 * Port of ../web/lib/pms.ts: the vehicle adapter over the generic
 * IntervalEngine (through IntervalStatus). Golden-tested against pms.json.
 *
 * A task is due on whichever of its two limits, `intervalKm` or
 * `intervalMonths`, arrives first; `governedBy` says which.
 */
final class Pms
{
    private const array STATUS_RANK = ['overdue' => 0, 'due_soon' => 1, 'ok' => 2];

    public static function odometerAgeDays(VehicleFacts $vehicle, DateTimeImmutable $today): int
    {
        return max(0, Calendar::differenceInCalendarDays($today, Calendar::parseDate($vehicle->odometerReadAt)));
    }

    public static function isOdometerStale(VehicleFacts $vehicle, DateTimeImmutable $today): bool
    {
        return self::odometerAgeDays($vehicle, $today) > FleetThresholds::ODOMETER_STALE_DAYS;
    }

    /**
     * One task for one vehicle. A task the vehicle has no history for is
     * assumed exactly one interval old on both limits: due now, not "fine".
     */
    public static function evaluateTask(VehicleFacts $vehicle, ServiceTaskFacts $task, DateTimeImmutable $today): PmsItem
    {
        $state = $vehicle->taskState[$task->id] ?? new TaskState(
            max(0, $vehicle->odometer - $task->intervalKm),
            Calendar::toDate(Calendar::addMonths($today, -$task->intervalMonths)),
        );

        $result = IntervalStatus::compute(
            Calendar::parseDate($state->lastDoneOn),
            $state->lastDoneOdometer,
            $task->intervalKm,
            $task->intervalMonths,
            $vehicle->odometer,
            Calendar::parseDate($vehicle->odometerReadAt),
            $vehicle->avgDailyKm,
            $today,
        );

        return new PmsItem(
            $task,
            $result->status === IntervalBand::OnSchedule ? 'ok' : $result->status->value,
            $result->kmRemaining,
            $result->daysRemaining,
            $result->progress,
            $result->distanceDueAt,
            Calendar::toDate($result->projectedDue),
            $result->governedBy,
            $state->lastDoneOn,
            $state->lastDoneOdometer,
        );
    }

    /** Most urgent first: worse status wins, then the nearer due date. */
    public static function compareUrgency(PmsItem $a, PmsItem $b): int
    {
        $byStatus = self::STATUS_RANK[$a->status] - self::STATUS_RANK[$b->status];

        return $byStatus !== 0 ? $byStatus : $a->daysRemaining - $b->daysRemaining;
    }

    /**
     * @param  list<ServiceTaskFacts>  $tasks
     */
    public static function evaluateVehicle(VehicleFacts $vehicle, array $tasks, DateTimeImmutable $today): VehicleHealth
    {
        $items = array_map(fn (ServiceTaskFacts $task): PmsItem => self::evaluateTask($vehicle, $task, $today), $tasks);
        usort($items, self::compareUrgency(...));

        $overdue = count(array_filter($items, fn (PmsItem $item): bool => $item->status === 'overdue'));
        $dueSoon = count(array_filter($items, fn (PmsItem $item): bool => $item->status === 'due_soon'));

        // Deliberately steep, and not to be softened: a vehicle with two
        // breached safety intervals must read as a problem, not a 90-something.
        $penalty = 0;
        foreach ($items as $item) {
            $penalty += match ($item->status) {
                'overdue' => $item->task->critical ? 25 : 15,
                'due_soon' => $item->task->critical ? 8 : 4,
                default => 0,
            };
        }

        $next = null;
        foreach ($items as $item) {
            if ($item->status !== 'ok') {
                $next = $item;
                break;
            }
        }

        return new VehicleHealth(
            $vehicle,
            $items,
            $overdue > 0 ? 'overdue' : ($dueSoon > 0 ? 'due_soon' : 'ok'),
            $overdue,
            $dueSoon,
            $next ?? $items[0] ?? null,
            (int) JsMath::round(JsMath::clamp(100 - $penalty, 0, 100)),
        );
    }

    /**
     * @param  list<VehicleFacts>  $vehicles
     * @param  list<ServiceTaskFacts>  $tasks
     * @return list<VehicleHealth>
     */
    public static function evaluateFleet(array $vehicles, array $tasks, DateTimeImmutable $today): array
    {
        return array_map(fn (VehicleFacts $vehicle): VehicleHealth => self::evaluateVehicle($vehicle, $tasks, $today), $vehicles);
    }

    /**
     * What closing a work order does to a vehicle: the listed tasks were done
     * at the service odometer, the odometer moves forward (never back), the
     * close is itself a fresh reading, and the vehicle is back in service.
     */
    public static function applyCompletion(VehicleFacts $vehicle, CompletedService $service, DateTimeImmutable $today): VehicleFacts
    {
        $doneOn = $service->completedOn ?? Calendar::toDate($today);

        $taskState = $vehicle->taskState;
        foreach ($service->taskIds as $taskId) {
            $taskState[$taskId] = new TaskState($service->odometerAtService, $doneOn);
        }

        return $vehicle->with(max($vehicle->odometer, $service->odometerAtService), $doneOn, $taskState, 'active');
    }
}
