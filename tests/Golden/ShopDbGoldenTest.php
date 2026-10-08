<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Golden\Support\WebFixtures;

/*
 * shop.json's sweeps over the whole seeded book, replayed end to end through
 * the shop endpoints: DemoSeeder loads ../web's 484 work orders into the
 * repair branch, the clock is frozen at the fixtures' instant, and each
 * answer must equal what ../web computed, fixture ids mapped to ULIDs.
 *
 *   GET /shop/arriving?date=      ← arrivingToday, 2026-10-05 … 2026-10-15
 *   GET /shop/floor?date=         ← floorUtilisation, same days
 *   GET /shop/in-progress         ← inProgress, whole book
 *   GET /shop/approvals           ← awaitingApproval, now (count, value, longest)
 *   GET /shop/revenue?from=&to=   ← revenueBetween / revenueByClient / revenueByServiceItem
 *   GET /shop/technicians?from=&to= ← technicianLoad
 *
 * The API takes calendar dates, so only the sweeps whose bounds are whole
 * Manila days replay (month to date, previous month); the clock is frozen,
 * so "to tomorrow" and ../web's "to now" count the same collections.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(WebFixtures::FROZEN_AT));
    $seeder = new DemoSeeder;
    $seeder->run();
    $this->ids = $seeder->ids;
    $this->branch = $seeder->ids[DemoSeeder::REPAIR_BRANCH];
    Sanctum::actingAs(asSystem(fn () => User::query()->where('email', 'advisor@mekanikomore.ph')->firstOrFail()));
});

/** A fixture case's output, money kept as centavos. */
function shopFixture(string $case): mixed
{
    $raw = collect(WebFixtures::raw('shop'))->firstWhere('case', $case) ?? throw new LogicException("No case {$case}.");

    return WebFixtures::canonical(WebFixtures::resolve($raw['output'], true));
}

/**
 * @param  list<array<string, mixed>>  $orders  fixture orders
 * @return list<string>
 */
function orderIds(array $ids, array $orders): array
{
    return array_map(fn (array $order): string => $ids[$order['id']], $orders);
}

it('lists the day\'s arrivals and books the floor exactly as the frontend', function () {
    $mismatches = [];
    for ($day = CarbonImmutable::parse('2026-10-05'); $day->toDateString() <= '2026-10-15'; $day = $day->addDay()) {
        $date = $day->toDateString();

        $arriving = $this->getJson("/api/v1/shop/arriving?branch_id={$this->branch}&date={$date}")->assertOk()->json('data.*.id');
        if ($arriving !== orderIds($this->ids, shopFixture("sweep › arrivingToday › {$date}"))) {
            $mismatches["arriving {$date}"] = $arriving;
        }

        $expected = shopFixture("sweep › floorUtilisation › {$date}");
        $floor = $this->getJson("/api/v1/shop/floor?branch_id={$this->branch}&date={$date}")->assertOk()->json('data');
        $actual = WebFixtures::canonical([
            'bookedHours' => $floor['booked_hours'],
            'capacityHours' => $floor['capacity_hours'],
            'utilisation' => $floor['utilisation'],
            'loads' => array_map(fn (array $bay): array => [
                'bayId' => $bay['bay_id'],
                'name' => $bay['name'],
                'bookedHours' => $bay['booked_hours'],
                'capacityHours' => $bay['capacity_hours'],
                'utilisation' => $bay['utilisation'],
                'jobs' => $bay['work_order_ids'],
            ], $floor['bays']),
        ]);
        $expected['loads'] = array_map(fn (array $load): array => ['bayId' => $this->ids['bay:'.$load['bayId']], 'jobs' => orderIds($this->ids, $load['jobs'])] + $load, $expected['loads']);
        if ($actual !== WebFixtures::canonical($expected)) {
            $mismatches["floor {$date}"] = ['expected' => $expected, 'actual' => $actual];
        }
    }

    expect(array_slice($mismatches, 0, 2, true))->toBe([], count($mismatches).' days differ');
});

it('lists the jobs on the floor, longest-running first', function () {
    expect($this->getJson('/api/v1/shop/in-progress')->assertOk()->json('data.*.work_order.id'))
        ->toBe(orderIds($this->ids, shopFixture('sweep › inProgress › whole book')));
});

it('totals the approval queue and puts the longest wait first', function () {
    $expected = shopFixture('sweep › awaitingApproval › now');
    $data = $this->getJson('/api/v1/shop/approvals')->assertOk()->json('data');

    $ids = array_column(array_column($data['orders'], 'work_order'), 'id');
    sort($ids);
    $expectedIds = orderIds($this->ids, $expected['orders']);
    sort($expectedIds);

    expect($data['count'])->toBe($expected['count'])
        ->and(['cents' => $data['total_value_cents']])->toBe($expected['totalValue'])
        ->and($ids)->toBe($expectedIds)
        ->and($data['orders'][0]['work_order']['id'])->toBe($this->ids[$expected['longest']['order']['id']])
        ->and(WebFixtures::canonical($data['orders'][0]['waiting_hours']))->toBe($expected['longest']['hours']);
});

it('recognises revenue on collection, by customer and by service item', function (string $sweep, string $from, string $to) {
    $data = $this->getJson("/api/v1/shop/revenue?branch_id={$this->branch}&from={$from}&to={$to}")->assertOk()->json('data');
    $rows = fn (array $rows): array => array_map(fn (array $r): array => ['name' => $r['name'], 'value' => ['cents' => $r['value_cents']]], $rows);

    expect(['cents' => $data['revenue_cents']])->toBe(shopFixture("sweep › revenueBetween › {$sweep}"))
        ->and(WebFixtures::canonical($rows($data['by_customer'])))->toBe(shopFixture("sweep › revenueByClient › {$sweep}"))
        ->and(WebFixtures::canonical($rows($data['by_service_item'])))->toBe(shopFixture("sweep › revenueByServiceItem › {$sweep}"));
})->with([
    'month to date' => ['month to date', '2026-10-01', '2026-10-09'],
    'previous month' => ['previous month', '2026-09-01', '2026-10-01'],
]);

it('reports technician load from the closed event, not the completion date', function (string $sweep, string $from, string $to) {
    $data = $this->getJson("/api/v1/shop/technicians?branch_id={$this->branch}&from={$from}&to={$to}")->assertOk()->json('data');
    $expected = array_map(fn (array $t): array => [
        'name' => $t['name'],
        'current' => $t['current'] === null ? null : $this->ids[$t['current']['id']],
        'completed' => $t['completedThisPeriod'],
        'actual' => $t['avgActualHours'],
        'estimated' => $t['avgEstimatedHours'],
    ], shopFixture("sweep › technicianLoad › {$sweep}"));

    expect(WebFixtures::canonical(array_map(fn (array $t): array => [
        'name' => $t['name'],
        'current' => $t['current_work_order_id'],
        'completed' => $t['completed_this_period'],
        'actual' => $t['avg_actual_hours'],
        'estimated' => $t['avg_estimated_hours'],
    ], $data)))->toBe(WebFixtures::canonical($expected));
})->with([
    'month to date' => ['month to date', '2026-10-01', '2026-10-09'],
    'previous month' => ['previous month', '2026-09-01', '2026-10-01'],
]);

it('keeps the shop floor to staff', function () {
    Sanctum::actingAs(asSystem(fn () => User::query()->where('email', 'donmiguel@mekanikomor.ph')->firstOrFail()));

    $this->getJson('/api/v1/shop/revenue')->assertForbidden();
});
