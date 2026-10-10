<?php

declare(strict_types=1);

use App\Domain\Detailing\DayPlanner;
use App\Domain\Detailing\PlannedDay;
use App\Domain\Detailing\ServiceDuration;
use App\Domain\Detailing\SizeClass;
use App\Domain\Detailing\SlotGrid;

/*
 * Characterization: the source's own JavaScript (bookingUtils.js), recorded.
 * See tests/Golden/fixtures/detailing/capture/README.md. A failure here means
 * the port blocks different slots than the app in production does.
 */

/**
 * @return array{grid: array{slot_minutes: int, closing_minutes: int, grid_end_minutes: int, slots: list<int>}, durations: list<array{text: string, minutes: int|float, days: int, slot_budget: int}>, plans: list<array{date: string, time_label: string, start_minutes: int, duration: string, closing_minutes: int, days: list<array{date: string, slots: list<int>}>}>}
 */
function plannerFixture(): array
{
    static $fixture = null;

    return $fixture ??= json_decode((string) file_get_contents(__DIR__.'/../../../Golden/fixtures/detailing/planner.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('has the source\'s grid: 30-minute slots, 8:00 AM to 9:00 PM, no lunch hour', function () {
    $grid = plannerFixture()['grid'];

    expect(SlotGrid::slots())->toBe($grid['slots'])
        ->and(SlotGrid::SLOT_MINUTES)->toBe($grid['slot_minutes'])
        ->and(SlotGrid::DEFAULT_CLOSING_MINUTES)->toBe($grid['closing_minutes'])
        ->and(SlotGrid::GRID_END_MINUTES)->toBe($grid['grid_end_minutes'])
        ->and(SlotGrid::isSlot(720))->toBeFalse()
        ->and(SlotGrid::isSlot(750))->toBeFalse();
});

it('reads a duration as the source does', function (string $text, int|float $minutes, int $days, int $budget) {
    expect(ServiceDuration::minutes($text))->toBe((float) $minutes)
        ->and(ServiceDuration::days($text))->toBe($days)
        ->and(ServiceDuration::slotBudget($text))->toBe($budget);
})->with(fn (): array => array_map(
    fn (array $case): array => [$case['text'], $case['minutes'], $case['days'], $case['slot_budget']],
    plannerFixture()['durations'],
));

it('plans every start, duration and closing time as the source does', function () {
    $differences = [];
    foreach (plannerFixture()['plans'] as $case) {
        $plan = DayPlanner::plan($case['date'], $case['start_minutes'], ServiceDuration::slotBudget($case['duration']), $case['closing_minutes']);
        $days = array_map(fn (PlannedDay $day): array => ['date' => $day->date, 'slots' => $day->slots], $plan->days);
        if ($days !== $case['days']) {
            $differences[] = sprintf('%s %s, closing %s', $case['time_label'], $case['duration'], SlotGrid::label($case['closing_minutes']));
        }
    }

    expect($differences)->toBe([])
        ->and(count(plannerFixture()['plans']))->toBeGreaterThan(700);
});

// ------------------------------------------------- the documented behaviour, by name

it('rounds an off-grid start to the nearest slot, ties going forward', function () {
    expect(SlotGrid::label(SlotGrid::nearest(SlotGrid::parseLabel('10:44 AM') ?? -1)))->toBe('10:30 AM')
        ->and(SlotGrid::label(SlotGrid::nearest(SlotGrid::parseLabel('10:45 AM') ?? -1)))->toBe('11:00 AM')
        ->and(SlotGrid::label(SlotGrid::nearest(SlotGrid::parseLabel('10:46 AM') ?? -1)))->toBe('11:00 AM')
        ->and(SlotGrid::label(SlotGrid::nearest(SlotGrid::parseLabel('1:45 PM') ?? -1)))->toBe('2:00 PM')
        // Across lunch: noon is nearer 11:30; 12:15 is a tie and goes forward.
        ->and(SlotGrid::label(SlotGrid::nearest(720)))->toBe('11:30 AM')
        ->and(SlotGrid::label(SlotGrid::nearest(735)))->toBe('1:00 PM')
        ->and(SlotGrid::label(SlotGrid::nearest(420)))->toBe('8:00 AM')
        ->and(SlotGrid::label(SlotGrid::nearest(1439)))->toBe('9:00 PM');
});

it('rounds a sub-slot duration up and takes a range\'s upper bound', function () {
    expect(ServiceDuration::slotBudget('45 min'))->toBe(2)
        ->and(ServiceDuration::slotBudget('2–3 hrs'))->toBe(6)
        ->and(ServiceDuration::slotBudget('1 hr 30 min'))->toBe(3)
        ->and(ServiceDuration::slotBudget('.5 hrs'))->toBe(1)
        ->and(ServiceDuration::slotBudget('2'))->toBe(4)
        ->and(ServiceDuration::slotBudget(''))->toBe(1)
        // A day is eight working hours: 16 slots, however the working day is cut.
        ->and(ServiceDuration::slotBudget('1–2 days'))->toBe(32);
});

it('rolls work that does not fit before closing into the next morning', function () {
    $plan = DayPlanner::plan('2026-11-02', 990, ServiceDuration::slotBudget('1 hr'), 1020);

    expect($plan->spansDays())->toBeTrue()
        ->and($plan->days[0]->date)->toBe('2026-11-02')
        ->and($plan->days[0]->slots)->toBe([990])
        ->and($plan->days[1]->date)->toBe('2026-11-03')
        ->and($plan->days[1]->slots)->toBe([480])
        ->and($plan->endMinutes())->toBe(510);
});

it('starts a booking made at or after closing on the next day', function () {
    $plan = DayPlanner::plan('2026-11-02', 1020, 2, 1020);

    expect($plan->firstDate())->toBe('2026-11-03')
        ->and($plan->days[0]->slots)->toBe([480, 510]);
});

it('never plans the lunch hour', function () {
    $plan = DayPlanner::plan('2026-11-02', 690, ServiceDuration::slotBudget('2 hrs'), 1020);

    expect($plan->days[0]->slots)->toBe([690, 780, 810, 840]);
});

it('fills a multi-day job with a partial last day, Sundays included', function () {
    // Saturday 10:00 AM, two days of work (32 slots): 12 on Saturday, 16 on Sunday, 4 on Monday.
    $plan = DayPlanner::plan('2026-11-07', 600, ServiceDuration::slotBudget('1–2 days'), 1020);

    expect(array_map(fn (PlannedDay $day): array => [$day->date, count($day->slots)], $plan->days))
        ->toBe([['2026-11-07', 12], ['2026-11-08', 16], ['2026-11-09', 4]])
        ->and($plan->days[2]->slots)->toBe([480, 510, 540, 570]);
});

it('gives up after sixty passes, leaving a job too long for the day short', function () {
    // One slot a day (closing 8:30 AM), five days of work (80 slots): the source stops at 60.
    $plan = DayPlanner::plan('2026-11-27', 480, ServiceDuration::slotBudget('5 days'), 510);

    expect(count($plan->days))->toBe(60)->and($plan->slotCount())->toBe(60);
});

it('plans nothing when no slot starts before closing', function () {
    expect(DayPlanner::plan('2026-11-02', 600, 2, 480)->isEmpty())->toBeTrue();
});

it('reads the size a source package name ends in', function () {
    expect(SizeClass::fromNameSuffix('The Essential (M)'))->toBe(SizeClass::Medium)
        ->and(SizeClass::fromNameSuffix('Ceramic Coating (XL)'))->toBe(SizeClass::Xl)
        ->and(SizeClass::fromNameSuffix('The Executive (s) '))->toBe(SizeClass::Small)
        ->and(SizeClass::fromNameSuffix('Interior Rescue'))->toBeNull()
        ->and(SizeClass::fromNameSuffix('Wash (Motorcycle)'))->toBeNull();
});
