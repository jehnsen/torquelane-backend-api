<?php

declare(strict_types=1);

use App\Domain\Analytics\AutoSchedule;
use App\Domain\Analytics\ScheduleProposal;
use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\ServiceTaskFacts;
use App\Domain\Fleet\VehicleFacts;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\Calendar;

/*
 * ../web's auto-schedule dialog: overdue items no live order covers, worst
 * first, from tomorrow at three a day; critical tasks booked as critical.
 */

function scheduledItem(string $task, string $status, int $daysRemaining, bool $critical = false): PmsItem
{
    return new PmsItem(new ServiceTaskFacts($task, ucfirst($task), 5000, 6, $critical), $status, -100, $daysRemaining, 1.2, 0, '2026-10-01', 'distance', '2026-01-01', 0);
}

/**
 * @param  list<PmsItem>  $items
 */
function scheduledVehicle(string $id, array $items): VehicleHealth
{
    return new VehicleHealth(new VehicleFacts($id, strtoupper($id), 0, '2026-10-08', 0, [], 'active', null, ''), $items, 'overdue', 0, 0, null, 50);
}

it('books overdue items worst first, three a day from tomorrow', function () {
    $health = [
        scheduledVehicle('a', [scheduledItem('oil', 'overdue', -3), scheduledItem('brakes', 'overdue', -40, true), scheduledItem('tyres', 'due_soon', 5)]),
        scheduledVehicle('b', [scheduledItem('oil', 'overdue', -10), scheduledItem('belt', 'overdue', -3), scheduledItem('air', 'overdue', -1)]),
    ];

    $proposals = AutoSchedule::propose($health, [], Calendar::local(new DateTimeImmutable('2026-10-08T10:00:00+08:00')));

    expect(array_map(fn (ScheduleProposal $p): array => [$p->vehicleId, $p->item->task->id, $p->scheduledFor, $p->priority], $proposals))->toBe([
        ['a', 'brakes', '2026-10-09', 'critical'],
        ['b', 'oil', '2026-10-09', 'high'],
        // A tie keeps fleet order.
        ['a', 'oil', '2026-10-09', 'high'],
        ['b', 'belt', '2026-10-10', 'high'],
        ['b', 'air', '2026-10-10', 'high'],
    ]);
});

it('leaves out what a live work order already covers', function () {
    $health = [scheduledVehicle('a', [scheduledItem('oil', 'overdue', -3), scheduledItem('brakes', 'overdue', -4)])];
    $covered = AutoSchedule::covered([
        ['vehicleId' => 'a', 'taskIds' => ['oil'], 'live' => true],
        ['vehicleId' => 'a', 'taskIds' => ['brakes'], 'live' => false],
    ]);

    $proposals = AutoSchedule::propose($health, $covered, Calendar::local(new DateTimeImmutable('2026-10-08T10:00:00+08:00')));

    expect(array_map(fn (ScheduleProposal $p): string => $p->item->task->id, $proposals))->toBe(['brakes']);
});
