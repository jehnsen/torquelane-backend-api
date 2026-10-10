<?php

declare(strict_types=1);

use App\Domain\Equipment\EquipmentEngine;
use App\Domain\Equipment\ServiceEvent;
use Tests\Support\EquipmentRecording;

/*
 * Characterization: the source's own equipment engine (equipmentUtils.js),
 * recorded for 101 tools across 46 situations. See
 * tests/Golden/fixtures/detailing/capture/README.md. Hours, ratios and days
 * must match the source to the last bit; money is compared in centavos (the
 * source worked in floating pesos, the port in whole centavos).
 */

/** A JSON number as the float the source held. */
function f(mixed $value): ?float
{
    return $value === null ? null : (float) $value;
}

it('reports every tool as the source does', function (array $scenario) {
    $now = (int) EquipmentRecording::ms($scenario['now']);
    $rows = [];

    foreach ($scenario['equipment'] as $index => $tool) {
        $facts = EquipmentRecording::facts($tool);
        $services = [];
        foreach ($scenario['ctx']['maintenanceLogs'] as $log) {
            if ($log['equipmentId'] === $tool['id']) {
                $services[] = new ServiceEvent($log['kind'], $log['costCents'], (float) $log['downtimeHours'], (int) EquipmentRecording::ms($log['performedAt']));
            }
        }
        $report = EquipmentEngine::report($facts, EquipmentRecording::usage($tool['id'], $scenario['ctx']), $services, $now);
        $expected = $scenario['expect']['reports'][$index];
        $label = "{$tool['id']}: ";

        expect($report['lifetime'])->toBe(['hours' => f($expected['lifetime']['hours']), 'cycles' => $expected['lifetime']['cycles'], 'jobs' => $expected['lifetime']['jobs']], $label.'lifetime');

        if ($expected['service'] === null) {
            expect($report['service'])->toBeNull($label.'untracked');
        } else {
            expect($report['service'])->not->toBeNull($label.'tracked');
            expect([
                'level' => $report['service']['level'],
                'progress' => $report['service']['progress'],
                'binding' => $report['service']['binding'],
                'triggers' => array_map(fn (array $t): array => ['key' => $t['key'], 'used' => $t['used'], 'interval' => $t['interval'], 'ratio' => $t['ratio'], 'remaining' => $t['remaining']], $report['service']['triggers']),
                'hoursSince' => $report['service']['hours_since'],
                'cyclesSince' => $report['service']['cycles_since'],
                'daysSince' => $report['service']['days_since'],
            ])->toBe([
                'level' => $expected['service']['level'],
                'progress' => f($expected['service']['progress']),
                'binding' => $expected['service']['binding'],
                'triggers' => array_map(fn (array $t): array => ['key' => $t['key'], 'used' => f($t['used']), 'interval' => f($t['interval']), 'ratio' => f($t['ratio']), 'remaining' => f($t['remaining'])], $expected['service']['triggers']),
                'hoursSince' => f($expected['service']['hoursSince']),
                'cyclesSince' => $expected['service']['cyclesSince'],
                'daysSince' => f($expected['service']['daysSince']),
            ], $label.'service');
        }

        expect($report['life'])->toBe($expected['life'] === null ? null : [
            'life' => f($expected['life']['life']),
            'used' => f($expected['life']['used']),
            'remaining' => f($expected['life']['remaining']),
            'ratio' => f($expected['life']['ratio']),
            'level' => $expected['life']['level'],
        ], $label.'life');

        expect($report['forecast'] === null ? null : ['days' => $report['forecast']['days'], 'date' => EquipmentRecording::iso($report['forecast']['at_ms']), 'rate' => $report['forecast']['rate']])
            ->toBe($expected['forecast'] === null ? null : ['days' => f($expected['forecast']['days']), 'date' => $expected['forecast']['date'], 'rate' => f($expected['forecast']['rate'])], $label.'forecast');

        $reliability = $expected['reliability'];
        expect([
            $report['reliability']['services'],
            $report['reliability']['repairs'],
            $report['reliability']['downtime_hours'],
            $report['reliability']['mtbf_hours'],
            $report['reliability']['availability_pct'],
            EquipmentRecording::iso($report['reliability']['last_serviced_at_ms']),
        ])->toBe([$reliability['services'], $reliability['repairs'], f($reliability['downtimeHours']), f($reliability['mtbfHours']), f($reliability['availabilityPct']), $reliability['lastServicedAt']], $label.'reliability')
            // The source summed floating pesos; the port sums centavos.
            ->and($report['reliability']['maintenance_cost_cents'])->toBe((int) round($reliability['maintenanceCost'] * 100), $label.'maintenance cost')
            ->and($report['reliability']['total_cost_cents'])->toBe((int) round($reliability['totalCost'] * 100), $label.'total cost');
        if ($reliability['costPerHour'] === null) {
            expect($report['reliability']['cost_per_hour_cents'])->toBeNull($label.'cost per hour');
        } else {
            expect(abs((float) $report['reliability']['cost_per_hour_cents'] - $reliability['costPerHour'] * 100))->toBeLessThanOrEqual(0.5001, $label.'cost per hour');
        }

        expect($report['utilization'])->toBe([
            'window_days' => $expected['utilization']['windowDays'],
            'hours' => f($expected['utilization']['hours']),
            'jobs' => $expected['utilization']['jobs'],
            'capacity_hours' => $expected['utilization']['capacityHours'],
            'pct' => f($expected['utilization']['pct']),
        ], $label.'utilization')
            ->and($report['health'])->toBe($expected['health'], $label.'health');

        $rows[] = [
            'tool' => $facts,
            'service_level' => $report['service']['level'] ?? null,
            'life_level' => $report['life']['level'] ?? null,
            'maintenance_cost_cents' => $report['reliability']['maintenance_cost_cents'],
            'health_score' => $report['health']['score'],
        ];
    }

    $summary = EquipmentEngine::summarize($rows);
    $expected = $scenario['expect']['summary'];
    expect([$summary['total'], $summary['active'], $summary['overdue'], $summary['due_soon'], $summary['down'], $summary['replace_watch'], $summary['untracked'], $summary['avg_health']])
        ->toBe([$expected['total'], $expected['active'], $expected['overdue'], $expected['dueSoon'], $expected['down'], $expected['replaceWatch'], $expected['untracked'], $expected['avgHealth']])
        ->and($summary['asset_value_cents'])->toBe((int) round($expected['assetValue'] * 100))
        ->and($summary['maintenance_spend_cents'])->toBe((int) round($expected['maintenanceSpend'] * 100));
})->with(fn (): array => array_column(
    array_map(fn (array $scenario): array => ['name' => $scenario['name'], 'data' => [$scenario]], EquipmentRecording::scenarios()),
    'data',
    'name',
));

it('covers every band and both kinds of forecast', function () {
    $levels = [];
    $bands = [];
    foreach (EquipmentRecording::scenarios() as $scenario) {
        foreach ($scenario['expect']['reports'] as $report) {
            $levels[$report['service']['level'] ?? 'untracked'] = true;
            $levels['life:'.($report['life']['level'] ?? 'unrated')] = true;
            $bands[$report['health']['band']] = true;
        }
    }
    ksort($levels);
    ksort($bands);

    expect(array_keys($levels))->toBe(['due_soon', 'life:aging', 'life:end_of_life', 'life:healthy', 'life:replace_soon', 'life:unrated', 'ok', 'overdue', 'untracked'])
        ->and(array_keys($bands))->toBe(['critical', 'fair', 'good', 'poor']);
});
