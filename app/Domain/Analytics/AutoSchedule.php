<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\Calendar;
use DateTimeImmutable;

/**
 * Port of ../web components/work-orders/auto-schedule-dialog.tsx: every
 * OVERDUE item no live work order already covers for that vehicle, worst
 * first, booked from tomorrow at three jobs a day; critical tasks go in as
 * critical, the rest as high.
 */
final class AutoSchedule
{
    public const int JOBS_PER_DAY = 3;

    /**
     * @param  list<VehicleHealth>  $health  in fleet order (ties keep it)
     * @param  array<string, true>  $covered  "vehicleId:taskId" of live orders (neither closed nor cancelled)
     * @return list<ScheduleProposal>
     */
    public static function propose(array $health, array $covered, DateTimeImmutable $today, int $jobsPerDay = self::JOBS_PER_DAY): array
    {
        $pending = [];
        foreach ($health as $entry) {
            foreach ($entry->items as $item) {
                if ($item->status === 'overdue' && ! isset($covered[$entry->vehicle->id.':'.$item->task->id])) {
                    $pending[] = [$entry->vehicle->id, $item];
                }
            }
        }
        usort($pending, fn (array $a, array $b): int => $a[1]->daysRemaining <=> $b[1]->daysRemaining);

        $proposals = [];
        foreach ($pending as $index => [$vehicleId, $item]) {
            $proposals[] = new ScheduleProposal(
                $vehicleId,
                $item,
                Calendar::toDate(Calendar::addDays($today, 1 + intdiv($index, max($jobsPerDay, 1)))),
                $item->task->critical ? 'critical' : 'high',
            );
        }

        return $proposals;
    }

    /**
     * Live orders' coverage, keyed "vehicleId:taskId".
     *
     * @param  list<array{vehicleId: string, taskIds: list<string>, live: bool}>  $orders
     * @return array<string, true>
     */
    public static function covered(array $orders): array
    {
        $covered = [];
        foreach ($orders as $order) {
            if ($order['live']) {
                foreach ($order['taskIds'] as $taskId) {
                    $covered[$order['vehicleId'].':'.$taskId] = true;
                }
            }
        }

        return $covered;
    }
}
