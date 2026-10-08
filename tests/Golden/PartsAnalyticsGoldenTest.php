<?php

declare(strict_types=1);

use Tests\Golden\Support\PartsAnalyticsPort;
use Tests\Golden\Support\WebFixtures;

/*
 * Replays ../web's parts forecast and analytics (pms-monitoring-frontend@d45871e,
 * frozen clock 2026-10-08T10:00+08:00) against the PHP ports, every case, exact:
 *
 *   parts-forecast.json  App\Domain\Parts\PartsForecast (demand + summary)
 *   analytics.json       App\Domain\Analytics\Analytics
 *
 * Money is compared in integer centavos. parts.json carries only constants
 * (the catalogue and which parts each task consumes); FleetPartsDbGoldenTest
 * checks the seeded catalogue against them.
 */

it('reproduces the frontend\'s parts forecast and analytics exactly', function (string $module) {
    $mismatches = [];
    $replayed = 0;

    foreach (WebFixtures::raw($module) as $i => $raw) {
        if (! in_array($raw['fn'], PartsAnalyticsPort::REPLAYED[$module], true)) {
            continue;
        }
        $expected = WebFixtures::canonical(WebFixtures::resolve($raw['output'], true));

        try {
            $actual = WebFixtures::canonical(PartsAnalyticsPort::call($raw['fn'], WebFixtures::resolve($raw['input'], true)));
        } catch (Throwable $e) {
            $actual = ['$threw' => $e::class.': '.$e->getMessage()];
        }

        $replayed++;
        if ($actual !== $expected) {
            $mismatches[sprintf('#%04d %s', $i, $raw['case'])] = ['expected' => $expected, 'actual' => $actual];
        }
    }

    expect($replayed)->toBeGreaterThan(0);
    expect(array_slice($mismatches, 0, 2, true))->toBe([], sprintf('%d of %d %s cases differ', count($mismatches), $replayed, $module));
})->with(array_keys(PartsAnalyticsPort::REPLAYED));

it('replays every function in the parts and analytics fixtures', function () {
    foreach (PartsAnalyticsPort::REPLAYED as $module => $functions) {
        $inFixture = array_values(array_unique(array_filter(
            array_column(WebFixtures::raw($module), 'fn'),
            fn (string $fn): bool => ! str_starts_with($fn, '$'),
        )));
        sort($inFixture);
        sort($functions);

        expect($inFixture)->toBe($functions, $module);
    }

    // parts.json is constants only: nothing to call.
    expect(array_values(array_unique(array_column(WebFixtures::raw('parts'), 'fn'))))->toBe(['$clock', '$constants']);
});
