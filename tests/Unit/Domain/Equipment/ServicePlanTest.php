<?php

declare(strict_types=1);

use App\Domain\Alerts\Alerts;
use App\Domain\Alerts\EquipmentAlertFacts;
use App\Domain\Equipment\EquipmentEngine;
use App\Domain\Equipment\ServicePlan;
use App\Domain\Equipment\ToolFacts;
use App\Domain\Equipment\UsageEvent;
use App\Domain\Maintenance\MeterKind;
use Tests\Support\EquipmentRecording;

/*
 * A tool's service plan runs on the Phase 2 maintenance engine
 * (`ServicePlan` over `IntervalEngine`): which limit trips first, and the
 * reading or date each falls due at. The bands, the forecast and the rest are
 * the source's formulas (EquipmentCharacterizationTest).
 */

const PLAN_NOW = 1_791_000_000_000; // 2026-10-03T04:00:00Z
const PLAN_DAY = 86_400_000;

function planTool(?float $hours, ?int $count, ?int $days, ?int $lastServiceAtMs, MeterKind $countKind = MeterKind::Cycles, float $baselineHours = 0.0, int $baselineCount = 0): ToolFacts
{
    return new ToolFacts('t', 'available', 'good', true, 0, $baselineHours, $baselineCount, $hours, $count, $days, $lastServiceAtMs, null, PLAN_NOW - 400 * PLAN_DAY, null, $countKind);
}

it('names the same first limit as the source on every recorded tool that has moved', function () {
    $compared = 0;
    foreach (EquipmentRecording::scenarios() as $scenario) {
        $now = (int) EquipmentRecording::ms($scenario['now']);
        foreach ($scenario['equipment'] as $index => $tool) {
            $expected = $scenario['expect']['reports'][$index]['service'];
            if ($expected === null) {
                continue;
            }
            $facts = EquipmentRecording::facts($tool);
            $usage = EquipmentRecording::usage($tool['id'], $scenario['ctx']);
            $since = EquipmentEngine::usage($usage, $facts->lastServiceAtMs);
            $anchor = $facts->lastServiceAtMs ?? $facts->purchaseDateMs ?? $facts->createdAtMs ?? $now;
            $plan = ServicePlan::evaluate($facts, EquipmentEngine::lifetime($facts, $usage), $since, max(0.0, fdiv($now - $anchor, PLAN_DAY)), $anchor, $now);

            // Nothing used and no calendar limit: the engine says nothing will ever fall due.
            $moved = array_sum(array_column($expected['triggers'], 'ratio')) > 0;
            expect($plan['governed_by'] ?? null)->toBe($moved ? $expected['binding'] : null, "{$scenario['name']} / {$tool['id']}");
            $compared++;
        }
    }

    expect($compared)->toBe(90);
});

it('says at what reading each meter limit falls due, and on what day the calendar limit does', function () {
    // Serviced 40 days ago at 300 h and 120 jobs on the clock; 60 h and 15 jobs since.
    $tool = planTool(100.0, 50, 90, PLAN_NOW - 40 * PLAN_DAY, baselineHours: 300.0, baselineCount: 120);
    $usage = [UsageEvent::logged(PLAN_NOW - 10 * PLAN_DAY, 60.0, 15)];

    $service = EquipmentEngine::serviceStatus($tool, $usage, PLAN_NOW);

    // 60% of the hours, 30% of the jobs, 44% of the days: hours trip first.
    expect($service['binding'])->toBe('hours')
        ->and($service['level'])->toBe('ok')
        ->and(array_column($service['triggers'], 'due_at', 'key'))->toBe(['hours' => 400.0, 'cycles' => 170.0, 'days' => null])
        ->and($service['triggers'][2]['due_on_ms'])->toBe(PLAN_NOW + 50 * PLAN_DAY)
        ->and($service['triggers'][0]['due_on_ms'])->toBeNull();
});

it('lets the calendar trip first when the meters are barely moving, and keeps the band on the limit furthest along', function () {
    $tool = planTool(100.0, null, 30, PLAN_NOW - 29 * PLAN_DAY);

    $idle = EquipmentEngine::serviceStatus($tool, [UsageEvent::logged(PLAN_NOW - PLAN_DAY, 5.0, 0)], PLAN_NOW);
    expect($idle['binding'])->toBe('days')->and($idle['level'])->toBe('due_soon');

    // 104 h of 100: overdue by the hours, which also trip first.
    $busy = EquipmentEngine::serviceStatus($tool, [UsageEvent::logged(PLAN_NOW - PLAN_DAY, 104.0, 0)], PLAN_NOW);
    expect($busy['binding'])->toBe('hours')->and($busy['level'])->toBe('overdue')->and($busy['progress'])->toBe(1.04);
});

it('falls back to the first limit when nothing has moved and no calendar limit is set', function () {
    $service = EquipmentEngine::serviceStatus(planTool(100.0, 50, null, null), [], PLAN_NOW);

    expect($service['binding'])->toBe('hours')->and($service['progress'])->toBe(0.0);
});

it('meters an espresso machine in cups', function () {
    $machine = planTool(null, 5000, null, PLAN_NOW - 20 * PLAN_DAY, MeterKind::Cups, baselineCount: 12_000);
    $usage = [UsageEvent::logged(PLAN_NOW - 25 * PLAN_DAY, 0.0, 900), UsageEvent::logged(PLAN_NOW - 2 * PLAN_DAY, 0.0, 4150)];

    $service = EquipmentEngine::serviceStatus($machine, $usage, PLAN_NOW);
    $forecast = EquipmentEngine::forecast($usage, $service, PLAN_NOW);

    expect($service['binding'])->toBe('cups')
        ->and($service['triggers'][0])->toMatchArray(['key' => 'cups', 'unit' => 'cups', 'used' => 4150.0, 'ratio' => 0.83, 'due_at' => 17_900.0])
        ->and($service['level'])->toBe('due_soon')
        // 5,050 cups in the last 30 days: 850 left is about five days.
        ->and(round($forecast['days'], 3))->toBe(5.05);
});

it('raises alerts for tools due, overdue, and near or past their rated life', function () {
    $today = new DateTimeImmutable('2026-10-12T08:30:00+08:00');
    $alerts = Alerts::build([], [], [], 24, $today, [
        new EquipmentAlertFacts('01aaaaaaaaaaaaaaaaaaaaaaaa', 'Espresso Machine', 'Descale and backflush', 'due_soon', 0.83, 'cups', 6.14, null, 0.0, 0.0),
        new EquipmentAlertFacts('01bbbbbbbbbbbbbbbbbbbbbbbb', 'Rotary Polisher', null, 'overdue', 1.04, 'hours', 0.0, 'end_of_life', 2012.4, 2000.0),
        new EquipmentAlertFacts('01cccccccccccccccccccccccc', 'Heat Gun', 'Element check', 'ok', 0.2, 'hours', 300.0, 'replace_soon', 1140.0, 1200.0),
        new EquipmentAlertFacts('01dddddddddddddddddddddddd', 'Idle Lamp', 'Emitter', 'due_soon', 0.9, 'hours', null, 'healthy', 10.0, 2000.0),
        new EquipmentAlertFacts('01eeeeeeeeeeeeeeeeeeeeeeee', 'Fine Tool', null, 'ok', 0.1, 'days', 80.0, 'aging', 800.0, 1000.0),
    ]);

    expect(array_map(fn ($a): array => [$a->id, $a->kind, $a->severity, $a->title, $a->body, $a->daysRemaining], $alerts))->toBe([
        ['equipment:01bbbbbbbbbbbbbbbbbbbbbbbb:service', 'equipment_service_overdue', 'critical', 'Service — Rotary Polisher', 'Overdue · 104% through its service interval (runtime hours).', 0],
        ['equipment:01bbbbbbbbbbbbbbbbbbbbbbbb:life', 'equipment_end_of_life', 'critical', 'Rotary Polisher has reached its rated life', '2,012 of 2,000 rated hours used (101%).', 0],
        ['equipment:01cccccccccccccccccccccccc:life', 'equipment_replace_soon', 'warning', 'Heat Gun is close to its rated life', '1,140 of 1,200 rated hours used (95%).', 0],
        ['equipment:01dddddddddddddddddddddddd:service', 'equipment_service_due_soon', 'warning', 'Emitter — Idle Lamp', 'Due soon · 90% through its service interval (runtime hours).', 0],
        ['equipment:01aaaaaaaaaaaaaaaaaaaaaaaa:service', 'equipment_service_due_soon', 'warning', 'Descale and backflush — Espresso Machine', 'Due soon · 83% through its service interval (cups served) · about 7 days left at the recent pace.', 7],
    ])
        ->and($alerts[0]->href)->toBe('/shop/equipment/01bbbbbbbbbbbbbbbbbbbbbbbb')
        ->and($alerts[0]->vehicleId)->toBeNull();
});
