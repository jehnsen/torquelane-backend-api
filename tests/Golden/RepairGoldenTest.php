<?php

declare(strict_types=1);

use Tests\Golden\Support\RepairPort;
use Tests\Golden\Support\SubCentavoInput;
use Tests\Golden\Support\WebFixtures;

/*
 * Replays ../web's repair modules (pms-monitoring-frontend@d45871e, frozen
 * clock 2026-10-08T10:00+08:00) against the PHP ports, every case:
 *
 *   work-order-machine.json  App\Domain\WorkOrders\WorkOrderMachine, WorkOrderReference
 *                            ($authorizeTransition through Access\AccessMatrix)
 *   approvals.json           App\Domain\Approvals\Approvals, Shared\BusinessHours
 *   billing.json             App\Domain\Billing\Billing
 *   checkin.json             App\Domain\CheckIn\CheckIn
 *   shop.json                App\Domain\Shop\Shop
 *
 * Money is asserted in integer centavos. The API rounds exact decimals
 * half-up once per amount; the TypeScript rounds IEEE floats. Where the two
 * part — a float landing on the wrong side of a half centavo, or an input
 * that is not a whole number of centavos and so cannot reach the API — the
 * case is pinned below BY NAME with its reason. Every other case must match
 * exactly, and every pinned case must still differ (a pin that starts
 * matching is stale and fails too).
 */

const SUB_CENTAVO_INPUT = 'Sub-centavo input: not representable in integer centavos.';

/** case name → why the API's answer differs from the float one */
const PINNED_REPAIR_DIVERGENCES = [
    // Float half-centavo artefacts: the exact answer differs.
    'billing :: sweep › roundMoney › 1.005' => '1.005 is 1.00499999… as a float, so Math.round lands on 1.00; exact half-up is 1.01.',
    'billing :: sweep › roundMoney › 1.015' => '1.015 is 1.01499999… as a float (Math.round → 1.01); exact half-up is 1.02.',
    'billing :: sweep › roundMoney › 1.255' => '1.255 is 1.25499999… as a float (Math.round → 1.25); exact half-up is 1.26.',
    'billing :: sweep › roundMoney › -1.005' => '-1.005 is -1.00499999… as a float (Math.round → -1.00); exact half-up (away from zero) is -1.01.',
    // Inputs that are not a whole number of centavos (a 3-decimal rate, a
    // sub-centavo fee): rates and fees are stored in centavos, so these
    // cannot reach the API at all.
    'billing :: sweep › linePartAmount › 3 × 0.335 (= 1.005)' => SUB_CENTAVO_INPUT,
    'billing :: sweep › lineAmount › 3 × 0.335 (= 1.005)' => SUB_CENTAVO_INPUT,
    'billing :: sweep › recalcLine › 3 × 0.335 (= 1.005)' => SUB_CENTAVO_INPUT,
    'billing :: sweep › linePartAmount › 1 × 1.005' => SUB_CENTAVO_INPUT,
    'billing :: sweep › lineAmount › 1 × 1.005' => SUB_CENTAVO_INPUT,
    'billing :: sweep › recalcLine › 1 × 1.005' => SUB_CENTAVO_INPUT,
    'billing :: sweep › linePartAmount › 1 × 2.675' => SUB_CENTAVO_INPUT,
    'billing :: sweep › lineAmount › 1 × 2.675' => SUB_CENTAVO_INPUT,
    'billing :: sweep › recalcLine › 1 × 2.675' => SUB_CENTAVO_INPUT,
    'billing :: sweep › linePartAmount › 1 × 1.015' => SUB_CENTAVO_INPUT,
    'billing :: sweep › lineAmount › 1 × 1.015' => SUB_CENTAVO_INPUT,
    'billing :: sweep › recalcLine › 1 × 1.015' => SUB_CENTAVO_INPUT,
    'billing :: sweep › linePartAmount › 1 × 0.005' => SUB_CENTAVO_INPUT,
    'billing :: sweep › lineAmount › 1 × 0.005' => SUB_CENTAVO_INPUT,
    'billing :: sweep › recalcLine › 1 × 0.005' => SUB_CENTAVO_INPUT,
    'billing :: sweep › linePartAmount › 7 × 0.145' => SUB_CENTAVO_INPUT,
    'billing :: sweep › lineAmount › 7 × 0.145' => SUB_CENTAVO_INPUT,
    'billing :: sweep › recalcLine › 7 × 0.145' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › no lines · VAT 12%, misc 99.995 · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › no lines · VAT 12%, misc 99.995 · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › no lines · VAT 12%, misc 99.995 · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › no lines · VAT 12%, misc 99.995 · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › no lines · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › one line · VAT 12%, misc 99.995 · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › one line · VAT 12%, misc 99.995 · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › one line · VAT 12%, misc 99.995 · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › one line · VAT 12%, misc 99.995 · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › one line · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › mixed statuses · VAT 12%, misc 99.995 · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › mixed statuses · VAT 12%, misc 99.995 · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › mixed statuses · VAT 12%, misc 99.995 · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › mixed statuses · VAT 12%, misc 99.995 · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › mixed statuses · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, no misc · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, no misc · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, no misc · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, no misc · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, no misc' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 150 · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 150 · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 150 · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 150 · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 150' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, no misc · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, no misc · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, no misc · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, no misc · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, no misc' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, misc 150 · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, misc 150 · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, misc 150 · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, misc 150 · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › per-line rounding would drift (three × 0.335 × 3) · VAT 0%, misc 150' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 99.995 · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 99.995 · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 99.995 · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 99.995 · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › per-line rounding would drift (three × 0.335 × 3) · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › sub-centavo labour on every line · VAT 12%, misc 99.995 · all lines' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › sub-centavo labour on every line · VAT 12%, misc 99.995 · approved' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › sub-centavo labour on every line · VAT 12%, misc 99.995 · approved + pending' => SUB_CENTAVO_INPUT,
    'billing :: sweep › computeTotals › sub-centavo labour on every line · VAT 12%, misc 99.995 · no statuses' => SUB_CENTAVO_INPUT,
    'billing :: sweep › approvedGrandTotal › sub-centavo labour on every line · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.005, labour 0 · VAT 12%, no misc' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.004, labour 0.004 · VAT 12%, no misc' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.005, labour 0 · VAT 12%, misc 150' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.004, labour 0.004 · VAT 12%, misc 150' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.005, labour 0 · VAT 0%, no misc' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.004, labour 0.004 · VAT 0%, no misc' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.005, labour 0 · VAT 0%, misc 150' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.004, labour 0.004 · VAT 0%, misc 150' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0, labour 0 · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.005, labour 0 · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 1234.5, labour 650 · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 0.004, labour 0.004 · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
    'billing :: sweep › totalsFromSubtotal › parts 99999.99, labour 0.01 · VAT 12%, misc 99.995' => SUB_CENTAVO_INPUT,
];

it('reproduces the frontend\'s repair rules exactly, divergences pinned by name', function (string $module) {
    $mismatches = [];
    $replayed = 0;

    foreach (WebFixtures::raw($module) as $i => $raw) {
        if (! in_array($raw['fn'], RepairPort::REPLAYED[$module], true)) {
            continue;
        }
        $expected = WebFixtures::canonical(WebFixtures::resolve($raw['output'], true));

        try {
            $actual = WebFixtures::canonical(RepairPort::call($raw['fn'], WebFixtures::resolve($raw['input'], true)));
        } catch (SubCentavoInput $e) {
            $actual = ['$subCentavoInput' => $e->getMessage()];
        } catch (Throwable $e) {
            $actual = ['$threw' => $e::class.': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()];
        }

        $replayed++;
        if ($actual !== $expected) {
            $mismatches[$raw['case']] = ['index' => $i, 'expected' => $expected, 'actual' => $actual];
        }
    }

    $pinned = array_filter(PINNED_REPAIR_DIVERGENCES, fn (string $case): bool => str_starts_with($case, $module.' :: '), ARRAY_FILTER_USE_KEY);
    $pinnedNames = array_map(fn (string $case): string => substr($case, strlen($module.' :: ')), array_keys($pinned));

    if (getenv('GOLDEN_DUMP')) {
        file_put_contents((string) getenv('GOLDEN_DUMP').'/'.$module.'.json', json_encode($mismatches, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $unexpected = array_diff_key($mismatches, array_flip($pinnedNames));
    $stale = array_values(array_diff($pinnedNames, array_keys($mismatches)));

    expect($replayed)->toBeGreaterThan(0)
        ->and(array_slice($unexpected, 0, 3, true))->toBe([], sprintf('%d of %d %s cases differ unpinned', count($unexpected), $replayed, $module))
        ->and($stale)->toBe([], 'pinned divergences that now match');
})->with(array_keys(RepairPort::REPLAYED));

it('replays every function in the repair fixtures', function () {
    foreach (RepairPort::REPLAYED as $module => $functions) {
        $inFixture = array_values(array_unique(array_filter(
            array_column(WebFixtures::raw($module), 'fn'),
            fn (string $fn): bool => ! in_array($fn, ['$clock', '$constants'], true),
        )));
        sort($inFixture);
        sort($functions);

        expect($inFixture)->toBe($functions, $module);
    }
});
