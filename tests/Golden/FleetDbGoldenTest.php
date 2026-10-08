<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Golden\Support\WebFixtures;

/*
 * The fleet fixtures replayed end to end, through the database and the API:
 * DemoSeeder loads demo-seed.json (vehicles, readings from which odometer and
 * daily rate are DERIVED, task state, documents, catalogue), the clock is
 * frozen at the fixtures' instant, and each answer must equal what ../web
 * computed from the same seed, with fixture ids mapped to the seeded ULIDs.
 *
 *   GET /vehicles/{id}/health  ← pms.json `$health` (evaluateFleet, whole seed fleet)
 *   GET /fleet/summary         ← pms.json summariseFleet sweeps (whole fleet, per client)
 *   GET /alerts                ← alerts.json buildAlerts sweeps, minus the
 *                                work-order alerts (no work orders until a later phase)
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(WebFixtures::FROZEN_AT));
    $seeder = new DemoSeeder;
    $seeder->run();
    $this->ids = $seeder->ids;
});

function signInAs(string $email): void
{
    Sanctum::actingAs(asSystem(fn (): User => User::query()->where('email', $email)->firstOrFail()));
}

/** Fixture ids inside a string (veh-001, doc-0065, the task code of a pms alert) → seeded ULIDs. */
function mapIds(array $ids, string $value): string
{
    $value = (string) preg_replace_callback('/^pms:(veh-\d+):([a-z-]+)$/', fn (array $m): string => 'pms:'.$ids[$m[1]].':'.$ids['task:'.$m[2]], $value);

    return (string) preg_replace_callback('/\b(veh-\d+|doc-\d+)\b/', fn (array $m): string => $ids[$m[1]], $value);
}

it('serves every seeded vehicle the health the frontend computed', function () {
    signInAs('owner@mekanikomore.ph');
    $mismatches = [];

    foreach (WebFixtures::health() as $entry) {
        $vehicleId = $entry['vehicle']['id'];
        $expected = WebFixtures::canonical([
            'status' => $entry['status'],
            'health_score' => $entry['healthScore'],
            'overdue_count' => $entry['overdueCount'],
            'due_soon_count' => $entry['dueSoonCount'],
            'items' => array_map(fn (array $item): array => [
                'service_task_id' => $this->ids['task:'.$item['task']['id']],
                'status' => $item['status'],
                'km_remaining' => $item['kmRemaining'],
                'days_remaining' => $item['daysRemaining'],
                'progress' => $item['progress'],
                'due_odometer' => $item['dueOdometer'],
                'due_date' => $item['dueDate'],
                'governed_by' => $item['governedBy'],
                'last_done_on' => $item['lastDoneOn'],
                'last_done_odometer' => $item['lastDoneOdometer'],
            ], $entry['items']),
            'odometer' => ['value' => $entry['vehicle']['odometer'], 'read_on' => $entry['vehicle']['odometerReadAt'], 'avg_daily_km' => $entry['vehicle']['avgDailyKm']],
        ]);

        $data = $this->getJson('/api/v1/vehicles/'.$this->ids[$vehicleId].'/health')->assertOk()->json('data');
        $actual = WebFixtures::canonical([
            'status' => $data['status'],
            'health_score' => $data['health_score'],
            'overdue_count' => $data['overdue_count'],
            'due_soon_count' => $data['due_soon_count'],
            'items' => array_map(fn (array $item): array => array_intersect_key($item, array_flip([
                'service_task_id', 'status', 'km_remaining', 'days_remaining', 'progress', 'due_odometer',
                'due_date', 'governed_by', 'last_done_on', 'last_done_odometer',
            ])), $data['items']),
            'odometer' => $data['odometer'],
        ]);

        if ($actual !== $expected) {
            $mismatches[$vehicleId] = ['expected' => $expected, 'actual' => $actual];
        }
    }

    expect(array_slice($mismatches, 0, 2, true))->toBe([], count($mismatches).' vehicles differ');
});

it('serves the dashboard KPIs the frontend computed, per scope', function (string $sweep, string $email, ?string $client) {
    $expected = collect(WebFixtures::raw('pms'))->firstWhere('case', 'sweep › summariseFleet › '.$sweep)['output'];
    signInAs($email);

    $query = $client === null ? '' : '?customer_account_id='.$this->ids[$client];
    $data = $this->getJson('/api/v1/fleet/summary'.$query)->assertOk()->json('data');

    expect([
        'total' => $data['total'],
        'compliant' => $data['compliant'],
        'dueSoon' => $data['due_soon'],
        'overdue' => $data['overdue'],
        'inService' => $data['in_service'],
        'down' => $data['down'],
        'complianceRate' => $data['compliance_rate'],
        'avgHealthScore' => $data['avg_health_score'],
        'totalOdometer' => $data['total_odometer'],
    ])->toBe($expected);
})->with([
    'staff, whole fleet' => ['whole seed fleet', 'owner@mekanikomore.ph', null],
    'staff, Bayani (suspended, still readable)' => ['fc-bayani', 'owner@mekanikomore.ph', 'fc-bayani'],
    'portal, Actimed' => ['fc-actimed', 'donmiguel@mekanikomor.ph', null],
    'portal, Northwind' => ['fc-northwind', 'fleet@northwind.ph', null],
    'portal, Sagrada' => ['fc-sagrada', 'operations@sagrada.ph', null],
]);

it('derives the alerts the frontend derived, per scope', function (string $sweep, string $email) {
    $fixture = collect(WebFixtures::raw('alerts'))->firstWhere('case', 'sweep › buildAlerts › '.$sweep)['output'];
    signInAs($email);

    $expected = array_values(array_map(fn (array $alert): array => [
        'id' => mapIds($this->ids, $alert['id']),
        'kind' => $alert['kind'],
        'severity' => $alert['severity'],
        'title' => $alert['title'],
        'body' => $alert['body'],
        'vehicle_id' => $alert['vehicleId'] === null ? null : $this->ids[$alert['vehicleId']],
        'href' => mapIds($this->ids, $alert['href']),
        'days_remaining' => $alert['daysRemaining'],
    ], array_filter($fixture, fn (array $alert): bool => ! in_array($alert['kind'], ['work_order_overdue', 'approval_sla_breach'], true))));

    $actual = array_map(fn (array $alert): array => array_diff_key($alert, ['read' => true, 'dismissed' => true]), $this->getJson('/api/v1/alerts')->assertOk()->json('data'));

    expect($expected)->not->toBeEmpty()
        ->and($actual)->toBe($expected);
})->with([
    'staff, whole fleet' => ['whole fleet', 'owner@mekanikomore.ph'],
    'portal, Actimed' => ['fc-actimed', 'donmiguel@mekanikomor.ph'],
    'portal, Northwind' => ['fc-northwind', 'fleet@northwind.ph'],
    'portal, Sagrada' => ['fc-sagrada', 'operations@sagrada.ph'],
]);
