<?php

declare(strict_types=1);

use App\Domain\Maintenance\DueSoonThresholds;
use App\Domain\Maintenance\IntervalBand;
use App\Domain\Maintenance\IntervalEngine;
use App\Domain\Maintenance\LastService;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Maintenance\MeterRate;
use App\Domain\Maintenance\MeterReading;
use App\Domain\Maintenance\MeterState;
use App\Domain\Maintenance\PlanItem;
use App\Domain\Shared\BusinessHours;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;

/*
 * The generic engine beyond the vehicle shape the golden tests pin (one km
 * meter + calendar): other meters, several meters, no calendar, a stationary
 * asset. Plus the JavaScript-compatible helpers the port depends on.
 */

function d(string $date): DateTimeImmutable
{
    return Calendar::parseDate($date);
}

$thresholds = new DueSoonThresholds(['hours' => 25, 'cycles' => 500], 14);

it('projects an hours meter with no calendar limit', function () use ($thresholds) {
    $outcome = IntervalEngine::evaluate(
        new PlanItem(['hours' => 250], null),
        new LastService(d('2026-09-01'), ['hours' => 1000]),
        ['hours' => new MeterState(MeterKind::Hours, 1200, d('2026-10-01'), 8)],
        $thresholds,
        d('2026-10-03'),
    );

    // 250 h at 8 h/day ≈ 31 days after 1 Sep; estimated now 1200 + 2×8 = 1216.
    expect($outcome->governedBy)->toBe('hours')
        ->and(Calendar::toDate($outcome->projectedDue))->toBe('2026-10-02')
        ->and($outcome->meter(MeterKind::Hours)?->estimatedNow)->toBe(1216)
        ->and($outcome->meter(MeterKind::Hours)?->remaining)->toBe(34)
        ->and($outcome->status)->toBe(IntervalBand::Overdue);
});

it('lets the earliest of several meters govern, ties to the first listed', function () use ($thresholds) {
    $meters = [
        'hours' => new MeterState(MeterKind::Hours, 0, d('2026-01-01'), 10),
        'cycles' => new MeterState(MeterKind::Cycles, 0, d('2026-01-01'), 100),
    ];
    $last = new LastService(d('2026-01-01'), ['hours' => 0, 'cycles' => 0]);

    $tie = IntervalEngine::evaluate(new PlanItem(['hours' => 100, 'cycles' => 1000], 12), $last, $meters, $thresholds, d('2026-01-02'));
    $cycles = IntervalEngine::evaluate(new PlanItem(['hours' => 100, 'cycles' => 500], 12), $last, $meters, $thresholds, d('2026-01-02'));

    expect($tie->governedBy)->toBe('hours')
        ->and($cycles->governedBy)->toBe('cycles')
        ->and(Calendar::toDate($cycles->projectedDue))->toBe('2026-01-06');
});

it('lets the calendar govern a stationary asset, and nothing falls due without one', function () use ($thresholds) {
    $meters = ['hours' => new MeterState(MeterKind::Hours, 500, d('2026-01-01'), 0)];
    $last = new LastService(d('2026-01-01'), ['hours' => 500]);

    $withCalendar = IntervalEngine::evaluate(new PlanItem(['hours' => 250], 3), $last, $meters, $thresholds, d('2026-02-01'));
    $without = IntervalEngine::evaluate(new PlanItem(['hours' => 250], null), $last, $meters, $thresholds, d('2026-02-01'));

    expect($withCalendar->governedBy)->toBe('time')
        ->and(Calendar::toDate($withCalendar->projectedDue))->toBe('2026-04-01')
        ->and($without->governedBy)->toBeNull()
        ->and($without->projectedDue)->toBeNull()
        ->and($without->status)->toBe(IntervalBand::OnSchedule);
});

it('derives the current reading and a trailing-window daily rate', function () {
    $readings = [
        new MeterReading('01a', 1000, '2026-01-01'),
        new MeterReading('01b', 4000, '2026-07-01'),
        new MeterReading('01c', 4600, '2026-08-01'),
        new MeterReading('01d', 5200, '2026-08-31'),
    ];

    // Window = 90 days before 31 Aug: the earliest reading inside it is 1 Jul.
    expect(MeterRate::current($readings)?->value)->toBe(5200)
        ->and(MeterRate::dailyRate($readings))->toBe((5200 - 4000) / 61)
        ->and(MeterRate::dailyRate([new MeterReading('01a', 1000, '2026-01-01')]))->toBe(0)
        // Nothing inside the window: the most recent reading before it.
        ->and(MeterRate::dailyRate([$readings[0], $readings[1]]))->toBe(3000 / 181);
});

it('rounds like JavaScript and adds months like date-fns', function () {
    expect(JsMath::round(2.5))->toBe(3)
        ->and(JsMath::round(-2.5))->toBe(-2)
        ->and(JsMath::round(0.49999999999999994))->toBe(0)
        ->and(Calendar::toDate(Calendar::addMonths(d('2026-01-31'), 1)))->toBe('2026-02-28')
        ->and(Calendar::toDate(Calendar::addMonths(d('2024-03-31'), -1)))->toBe('2024-02-29')
        ->and(Calendar::toDate(Calendar::addMonths(d('2026-10-08'), -18)))->toBe('2025-04-08');
});

it('counts business hours, Mon–Fri 08:00–18:00 Manila', function () {
    $friday = new DateTimeImmutable('2026-10-09T17:00:00+08:00');
    $monday = new DateTimeImmutable('2026-10-12T09:30:00+08:00');

    expect(BusinessHours::between($friday, $monday))->toBe(2.5)
        ->and(BusinessHours::between($monday, $friday))->toBe(0);
});
