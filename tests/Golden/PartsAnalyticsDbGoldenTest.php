<?php

declare(strict_types=1);

use App\Actions\Analytics\AnalyticsQueries;
use App\Domain\Analytics\Analytics;
use App\Domain\Analytics\AnalyticsOrder;
use App\Domain\Shop\NamedValue;
use App\Http\Resources\AnalyticsJson;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Golden\Support\WebFixtures;

/*
 * The parts and analytics fixtures replayed end to end, through the seeded
 * database and the API, with the clock frozen at the fixtures' instant. Each
 * answer must equal what ../web computed from the same seed (fixture ids
 * mapped to the seeded ULIDs, money in integer centavos):
 *
 *   GET /fleet-parts             ← parts.json constants (catalogue, usages, vendors)
 *   GET /demand-forecast         ← parts-forecast.json `fc-actimed, 6 weeks` sweep
 *   GET /analytics/dashboard     ← analytics.json monthlyCosts (12 months),
 *                                  upcomingLoad, serviceDemand, urgentItems,
 *                                  rollingSpend (30 days) sweeps, per scope
 *   GET /analytics/reports       ← monthlyCosts (6 months), fleetKmInPeriod (365 days)
 *   AnalyticsQueries → domain    ← spendByVehicle, spendByCategory,
 *                                  serviceFrequency, meanDaysBetweenServices,
 *                                  fleetKmInPeriod (30 days) over EVERY order in
 *                                  scope, as the sweeps call them (the reports
 *                                  screen narrows to its window, as ../web's does)
 *
 * The forecast sweeps below are PINNED, not replayed: ../web kept ONE
 * fleet-wide parts list, so its whole-fleet and per-client sweeps price every
 * client's due items against the same shelf. Here stock is per customer
 * account (the brief: the customer's own spare parts), and the seed gives
 * Actimed's catalogue to Actimed alone; those sweeps have no equivalent.
 */

const FORECAST_PINNED = [
    'sweep › computePartsDemand › whole fleet, 1 weeks', 'sweep › summariseDemand › whole fleet, 1 weeks',
    'sweep › computePartsDemand › whole fleet, 2 weeks', 'sweep › summariseDemand › whole fleet, 2 weeks',
    'sweep › computePartsDemand › whole fleet, 4 weeks', 'sweep › summariseDemand › whole fleet, 4 weeks',
    'sweep › computePartsDemand › whole fleet, 6 weeks', 'sweep › summariseDemand › whole fleet, 6 weeks',
    'sweep › computePartsDemand › whole fleet, 12 weeks', 'sweep › summariseDemand › whole fleet, 12 weeks',
    'sweep › computePartsDemand › fc-northwind, 6 weeks', 'sweep › summariseDemand › fc-northwind, 6 weeks',
    'sweep › computePartsDemand › fc-sagrada, 6 weeks', 'sweep › summariseDemand › fc-sagrada, 6 weeks',
    'sweep › computePartsDemand › fc-bayani, 6 weeks', 'sweep › summariseDemand › fc-bayani, 6 weeks',
    'sweep › computePartsDemand › no work and no purchase orders covering anything', 'sweep › summariseDemand › nothing due',
];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(WebFixtures::FROZEN_AT));
    $seeder = new DemoSeeder;
    $seeder->run();
    $this->ids = $seeder->ids;
});

function goldenSignIn(string $email): void
{
    Sanctum::actingAs(asSystem(fn (): User => User::query()->where('email', $email)->firstOrFail()));
}

/** A sweep's raw output (money still as {"$money", "cents"}). */
function sweepOutput(string $module, string $case): mixed
{
    $found = collect(WebFixtures::raw($module))->firstWhere('case', 'sweep › '.$case);

    return is_array($found) ? $found['output'] : throw new LogicException("No sweep {$case} in {$module}.json");
}

function cents(mixed $money): int
{
    return is_array($money) && is_int($money['cents'] ?? null) ? $money['cents'] : throw new LogicException('Not a money value: '.json_encode($money));
}

/**
 * A fixture urgent item → [vehicle ULID, task ULID].
 *
 * @param  array<string, string>  $ids
 * @return array{0: string, 1: string}
 */
function urgentKey(array $ids, mixed $entry): array
{
    $vehicle = is_array($entry) ? WebFixtures::resolve($entry['vehicle']) : null;
    $item = is_array($entry) ? WebFixtures::resolve($entry['item']) : null;

    return [$ids[$vehicle['id']], $ids['task:'.$item['task']['id']]];
}

dataset('analytics scopes', [
    'staff, whole fleet' => ['whole fleet', 'owner@mekanikomore.ph', null],
    'portal, Actimed' => ['fc-actimed', 'donmiguel@mekanikomor.ph', null],
    'portal, Northwind' => ['fc-northwind', 'fleet@northwind.ph', null],
    'portal, Sagrada' => ['fc-sagrada', 'operations@sagrada.ph', null],
    'staff, Bayani (suspended, still readable)' => ['fc-bayani', 'owner@mekanikomore.ph', 'fc-bayani'],
]);

it('serves the dashboard series the frontend computed, per scope', function (string $scope, string $email, ?string $client) {
    goldenSignIn($email);
    $query = $client === null ? '' : '?customer_account_id='.$this->ids[$client];
    $data = $this->getJson('/api/v1/analytics/dashboard'.$query)->assertOk()->json('data');

    expect($data['monthly_costs'])->toBe(array_map(fn (array $p): array => [
        'key' => $p['key'],
        'month' => $p['month'],
        'parts_cents' => cents($p['parts']),
        'labor_cents' => cents($p['labor']),
        'total_cents' => cents($p['total']),
        'preventive' => $p['preventive'],
        'corrective' => $p['corrective'],
    ], sweepOutput('analytics', "monthlyCosts › {$scope}, 12 months")));

    expect($data['upcoming_load'])->toBe(array_map(fn (array $b): array => [
        'label' => $b['label'], 'range' => $b['range'], 'overdue' => $b['overdue'], 'due_soon' => $b['dueSoon'], 'upcoming' => $b['upcoming'],
    ], sweepOutput('analytics', "upcomingLoad › {$scope}")));

    $demand = sweepOutput('analytics', "serviceDemand › {$scope}");
    $band = fn (array $b): array => ['count' => $b['count'], 'vehicle_count' => $b['vehicleCount'], 'estimated_cost_cents' => cents($b['estimatedCost'])];
    expect($data['demand'])->toBe(['overdue' => $band($demand['overdue']), 'due_soon' => $band($demand['dueSoon'])]);

    $spend = sweepOutput('analytics', "rollingSpend › {$scope}, 30 days");
    expect($data['spend'])->toBe([
        'window_days' => $spend['windowDays'],
        'current_cents' => cents($spend['current']),
        'previous_cents' => cents($spend['previous']),
        'delta_pct' => $spend['deltaPct'],
    ]);

    $urgent = sweepOutput('analytics', "urgentItems › {$scope}");
    expect($data['attention']['total'])->toBe(count($urgent))
        ->and(array_map(fn (array $u): array => [$u['vehicle']['id'], $u['item']['service_task_id']], $data['attention']['items']))
        ->toBe(array_map(fn (mixed $u): array => urgentKey($this->ids, $u), array_slice($urgent, 0, 3)));
})->with('analytics scopes');

/**
 * Runs `$callback` as `$email` would be scoped (optionally narrowed to one
 * account), outside HTTP.
 *
 * @template T
 *
 * @param  Closure(AnalyticsQueries, ?CustomerAccount): T  $callback
 * @return T
 */
function asScope(string $email, ?string $accountId, Closure $callback): mixed
{
    $user = asSystem(fn (): User => User::query()->where('email', $email)->firstOrFail());
    $context = app(TenantContextResolver::class)->resolve($user, null)->context ?? throw new LogicException("{$email} has no scope.");

    return app(TenantManager::class)->actingAs($context, function () use ($callback, $accountId): mixed {
        $analytics = app(AnalyticsQueries::class);

        return $callback($analytics, $analytics->account($accountId));
    });
}

/*
 * The rankings over EVERY order in scope, as the sweeps call them: the
 * seeded database through the API's own adapters (AnalyticsQueries) into the
 * domain. The reports endpoint narrows them to orders closed in its window,
 * as ../web's reports page does (tested in AnalyticsTest).
 */
it('ranks the seeded fleet exactly as the frontend did, per scope', function (string $scope, string $email, ?string $client) {
    $actual = asScope($email, $client === null ? null : $this->ids[$client], function (AnalyticsQueries $analytics, ?CustomerAccount $account): array {
        $views = $analytics->vehicles($account);
        $orders = $analytics->analyticsOrders($analytics->workOrders($account));
        $rankable = AnalyticsQueries::rankable($views);
        $named = fn (array $rows, bool $money): array => array_map(fn (NamedValue $r): array => ['name' => $r->name, 'value' => $money ? ['cents' => $r->value] : $r->value, 'meta' => $r->meta], $rows);

        return [
            'spendByVehicle' => $named(Analytics::spendByVehicle($orders, $rankable), true),
            'spendByCategory' => $named(Analytics::spendByCategory($orders, $analytics->fleet()->taskFacts()), true),
            'serviceFrequency' => $named(Analytics::serviceFrequency($orders, $rankable), false),
            'meanDaysBetweenServices' => Analytics::meanDaysBetweenServices($orders, count($views)),
            'fleetKmInPeriod' => Analytics::fleetKmInPeriod($rankable, 30),
        ];
    });

    $expected = [];
    foreach (['spendByVehicle', 'spendByCategory', 'serviceFrequency', 'meanDaysBetweenServices'] as $fn) {
        $expected[$fn] = WebFixtures::canonical(WebFixtures::resolve(sweepOutput('analytics', "{$fn} › {$scope}"), true));
    }
    $expected['fleetKmInPeriod'] = sweepOutput('analytics', "fleetKmInPeriod › {$scope}, 30 days");
    $expected['spendByCategory'] = array_map(fn (array $r): array => $r + ['meta' => null], $expected['spendByCategory']);

    expect(WebFixtures::canonical($actual))->toBe(WebFixtures::canonical($expected));
})->with('analytics scopes');

it('serves the report rankings from orders closed in the window', function (string $scope, string $email, ?string $client) {
    goldenSignIn($email);
    $query = $client === null ? '' : '&customer_account_id='.$this->ids[$client];
    $data = $this->getJson('/api/v1/analytics/reports?months=12'.$query)->assertOk()->json('data');

    $window = asScope($email, $client === null ? null : $this->ids[$client], function (AnalyticsQueries $analytics, ?CustomerAccount $account): array {
        $keys = array_column(AnalyticsJson::monthly(Analytics::monthlyCosts([], 12, $analytics->fleet()->today())), 'key');
        $orders = array_values(array_filter(
            $analytics->analyticsOrders($analytics->workOrders($account)),
            fn (AnalyticsOrder $o): bool => $o->isClosed() && $o->completedOn !== null && in_array(substr($o->completedOn, 0, 7), $keys, true),
        ));

        return ['count' => count($orders), 'total' => array_sum(array_map(fn (AnalyticsOrder $o): int => $o->costCents, $orders))];
    });

    // Exactly the orders closed in the window's months, and the monthly series sums to the same total.
    expect($data['closed_orders'])->toBe($window['count'])
        ->and($data['total_spend_cents'])->toBe($window['total'])
        ->and($data['total_spend_cents'])->toBe(array_sum(array_column($data['monthly_costs'], 'total_cents')));
})->with('analytics scopes');

it('serves the six-month cost trend and the year\'s fleet distance the frontend computed', function () {
    goldenSignIn('owner@mekanikomore.ph');

    $six = $this->getJson('/api/v1/analytics/reports?months=6')->assertOk()->json('data.monthly_costs');
    expect(array_column($six, 'total_cents'))->toBe(array_map(fn (array $p): int => cents($p['total']), sweepOutput('analytics', 'monthlyCosts › whole fleet, 6 months')))
        ->and(array_column($six, 'key'))->toBe(array_column(sweepOutput('analytics', 'monthlyCosts › whole fleet, 6 months'), 'key'));

    expect($this->getJson('/api/v1/analytics/reports?months=12')->json('data.period_km'))
        ->toBe(sweepOutput('analytics', 'fleetKmInPeriod › whole fleet, 365 days'));
});

it('seeds the frontend\'s parts catalogue as Actimed\'s own', function () {
    $constants = collect(WebFixtures::raw('parts'))->firstWhere('fn', '$constants')['output'];
    $state = collect(WebFixtures::seed()['state']['parts'])->keyBy('id');
    goldenSignIn('donmiguel@mekanikomor.ph');
    $parts = $this->getJson('/api/v1/fleet-parts?per_page=100')->assertOk()->json('data');

    expect(array_map(fn (array $p): array => [
        $p['sku'], $p['name'], $p['category'], $p['unit'], $p['unit_cost_cents'], $p['reorder_point'], $p['preferred_vendor'], $p['lead_time_days'], $p['current_stock'],
    ], $parts))->toBe(array_map(fn (array $d): array => [
        $d['sku'], $d['name'], $d['category'], $d['unit'], cents($d['unitCost']), $d['reorderPoint'], $d['preferredVendor'], $d['leadTimeDays'], $state[$d['id']]['currentStock'],
    ], $constants['PART_DEFINITIONS']));

    $skuOf = collect($constants['PART_DEFINITIONS'])->pluck('sku', 'id');
    $usages = [];
    foreach ($parts as $part) {
        foreach ($part['usages'] as $usage) {
            $usages[] = [$usage['service_task_id'], $part['sku'], $usage['quantity_per_service']];
        }
    }
    $expected = array_map(fn (array $u): array => [$this->ids['task:'.$u['serviceTaskId']], $skuOf[$u['partId']], $u['quantityPerService']], $constants['SERVICE_ITEM_PARTS']);
    sort($usages);
    sort($expected);
    expect($usages)->toBe($expected);

    // PART_VENDORS are the parts' suppliers (a part's preferred vendor), not
    // the provider's service vendors (pms_vendors, seeded as /vendors).
    $preferred = array_values(array_unique(array_column($parts, 'preferred_vendor')));
    expect(array_values(array_diff($preferred, $constants['PART_VENDORS'])))->toBe([])
        ->and(array_values(array_diff($constants['PART_VENDORS'], $preferred)))->toBe([]);
});

it('forecasts Actimed\'s parts demand exactly as the frontend did', function (string $email, bool $staff) {
    goldenSignIn($email);
    $query = '?horizon_weeks=6'.($staff ? '&customer_account_id='.$this->ids['fc-actimed'] : '');
    $data = $this->getJson('/api/v1/demand-forecast'.$query)->assertOk()->json('data');
    $state = collect(WebFixtures::seed()['state']['parts'])->keyBy('id');

    $expected = array_map(function (array $row) use ($state): array {
        $part = $state[substr($row['part']['$seed'], strlen('state/parts/'))];

        return [
            'sku' => $part['sku'],
            'quantity_required' => $row['quantityRequired'],
            'shortfall' => $row['shortfall'],
            'estimated_cost_cents' => cents($row['estimatedCost']),
            'earliest_needed_on' => $row['earliestNeededOn'],
            'lead_time_risk' => $row['leadTimeRisk'],
            'contributing_items' => array_map(fn (array $c): array => [$this->ids[$c['vehicleId']], $this->ids['task:'.$c['taskId']], $c['dueDate']], $row['contributingItems']),
        ];
    }, sweepOutput('parts-forecast', 'computePartsDemand › fc-actimed, 6 weeks'));

    $actual = array_map(fn (array $row): array => [
        'sku' => $row['part']['sku'],
        'quantity_required' => $row['quantity_required'],
        'shortfall' => $row['shortfall'],
        'estimated_cost_cents' => $row['estimated_cost_cents'],
        'earliest_needed_on' => $row['earliest_needed_on'],
        'lead_time_risk' => $row['lead_time_risk'],
        'contributing_items' => array_map(fn (array $c): array => [$c['vehicle_id'], $c['service_task_id'], $c['due_date']], $row['contributing_items']),
    ], $data['rows']);

    expect($expected)->not->toBeEmpty()
        ->and($actual)->toBe($expected)
        ->and($data['summary'])->toBe(sweepOutput('parts-forecast', 'summariseDemand › fc-actimed, 6 weeks'));
})->with([
    'portal, Actimed' => ['donmiguel@mekanikomor.ph', false],
    'staff, naming Actimed' => ['owner@mekanikomore.ph', true],
]);

it('replays or pins every forecast sweep', function () {
    $sweeps = array_values(array_filter(array_column(WebFixtures::raw('parts-forecast'), 'case'), fn (string $c): bool => str_starts_with($c, 'sweep › ')));
    $replayed = ['sweep › computePartsDemand › fc-actimed, 6 weeks', 'sweep › summariseDemand › fc-actimed, 6 weeks'];

    expect(array_values(array_diff($sweeps, $replayed, FORECAST_PINNED)))->toBe([])
        ->and(array_values(array_diff(FORECAST_PINNED, $sweeps)))->toBe([]);

    // The pin's premise: another account has no shelf of its own here.
    goldenSignIn('fleet@northwind.ph');
    expect($this->getJson('/api/v1/demand-forecast')->assertOk()->json('data.rows'))->toBe([]);
});
