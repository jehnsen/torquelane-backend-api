<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentKind;
use App\Domain\Fleet\FleetThresholds;
use Tests\Golden\Support\FleetPort;
use Tests\Golden\Support\WebFixtures;

/*
 * Replays ../web's fleet modules (pms-monitoring-frontend@d45871e, frozen
 * clock 2026-10-08T10:00+08:00) against the PHP ports, every case, exact:
 *
 *   pms.json                  App\Domain\Fleet\Pms, FleetSummary, WorkOrders\WorkOrderCosting
 *   interval-status.json      App\Domain\Fleet\IntervalStatus (→ Maintenance\IntervalEngine)
 *   odometer-validation.json  App\Domain\Fleet\OdometerValidation
 *   compliance.json           App\Domain\Fleet\Compliance
 *   alerts.json               App\Domain\Alerts\Alerts
 *
 * One test per module (2,500+ cases would otherwise each pay for an app boot
 * and a transaction); a failure lists every mismatching case by name.
 *
 * Money (workOrderCost, resolvePartsCost) is asserted in integer centavos,
 * as the fixtures README intends: the API computes it in exact decimals (R6),
 * not IEEE floats.
 */

it('reproduces the frontend\'s fleet rules exactly', function (string $module) {
    $mismatches = [];
    $replayed = 0;

    foreach (WebFixtures::raw($module) as $i => $raw) {
        if (! in_array($raw['fn'], FleetPort::REPLAYED[$module], true)) {
            continue;
        }
        $money = in_array($raw['fn'], FleetPort::MONEY, true);
        $expected = WebFixtures::canonical(WebFixtures::resolve($raw['output'], $money));

        try {
            $actual = WebFixtures::canonical(FleetPort::call($raw['fn'], WebFixtures::resolve($raw['input'], $money)));
        } catch (Throwable $e) {
            $actual = ['$threw' => $e::class.': '.$e->getMessage()];
        }

        $replayed++;
        if ($actual !== $expected) {
            $mismatches[sprintf('#%04d %s', $i, $raw['case'])] = ['expected' => $expected, 'actual' => $actual];
        }
    }

    expect($replayed)->toBeGreaterThan(0);
    expect(array_slice($mismatches, 0, 3, true))->toBe([], sprintf('%d of %d %s cases differ', count($mismatches), $replayed, $module));
})->with(array_keys(FleetPort::REPLAYED));

it('replays every function in the fleet fixtures', function () {
    foreach (FleetPort::REPLAYED as $module => $functions) {
        $inFixture = array_values(array_unique(array_filter(
            array_column(WebFixtures::raw($module), 'fn'),
            fn (string $fn): bool => ! str_starts_with($fn, '$'),
        )));
        sort($inFixture);
        sort($functions);

        expect($inFixture)->toBe($functions, $module);
    }
});

it('keeps the ported thresholds at the frontend\'s values', function () {
    $constants = fn (string $module): array => collect(WebFixtures::raw($module))->firstWhere('fn', '$constants')['output'];

    expect($constants('pms'))->toBe([
        'DUE_SOON_KM' => FleetThresholds::DUE_SOON_KM,
        'DUE_SOON_DAYS' => FleetThresholds::DUE_SOON_DAYS,
        'ODOMETER_STALE_DAYS' => FleetThresholds::ODOMETER_STALE_DAYS,
    ])
        ->and($constants('interval-status'))->toBe(['DUE_SOON_KM' => FleetThresholds::DUE_SOON_KM, 'DUE_SOON_DAYS' => FleetThresholds::DUE_SOON_DAYS])
        ->and($constants('alerts'))->toBe(['DOCUMENT_EXPIRY_WARNING_DAYS' => FleetThresholds::DOCUMENT_EXPIRY_WARNING_DAYS])
        ->and($constants('odometer-validation'))->toEqual([
            'ODOMETER_RATE_HIGH_MULTIPLIER' => FleetThresholds::ODOMETER_RATE_HIGH_MULTIPLIER,
            'ODOMETER_RATE_LOW_MULTIPLIER' => FleetThresholds::ODOMETER_RATE_LOW_MULTIPLIER,
        ])
        ->and($constants('compliance'))->toBe([
            'COMPLIANCE_DOC_KINDS' => array_values(array_map(
                fn (DocumentKind $kind): string => $kind->value,
                array_filter(DocumentKind::cases(), fn (DocumentKind $kind): bool => $kind->isCompliance()),
            )),
            'DASHBOARD_EXPIRY_WINDOW_DAYS' => FleetThresholds::DASHBOARD_EXPIRY_WINDOW_DAYS,
            'BADGE_WARNING_DAYS' => FleetThresholds::BADGE_WARNING_DAYS,
        ]);
});
