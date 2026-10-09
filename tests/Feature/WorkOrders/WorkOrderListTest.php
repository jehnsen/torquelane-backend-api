<?php

declare(strict_types=1);

use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * What the work-order list screens filter and total on the server (R2: the
 * frontend never sums an authoritative value), and the check-out panel's
 * release of several finished jobs at once.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

it('filters by stage, type and text, and totals the filtered set', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $summary = $this->getJson('/api/v1/work-orders/summary')->assertOk()->json('data');
    $all = asSystem(fn () => WorkOrder::query()->where('organization_id', $this->world->id('prov-mekanikomore'))->get());
    expect($summary['buckets'])->toBe([
        'active' => $all->whereNotIn('status.value', ['closed', 'cancelled'])->count(),
        'completed' => $all->where('status.value', 'closed')->count(),
        'cancelled' => $all->where('status.value', 'cancelled')->count(),
        'all' => $all->count(),
    ]);

    $completed = $this->getJson('/api/v1/work-orders?stage=completed&type=corrective&sort=completed&per_page=100')->assertOk();
    $rows = $completed->json('data');
    expect($rows)->not->toBeEmpty()
        ->and(array_unique(array_column($rows, 'status')))->toBe(['closed'])
        ->and(array_unique(array_column($rows, 'type')))->toBe(['corrective']);
    $dates = array_column($rows, 'completed_on');
    $sorted = $dates;
    rsort($sorted);
    expect($dates)->toBe($sorted);

    // The filtered value is the sum of each order's cost, labour plus resolved parts.
    $filtered = $this->getJson('/api/v1/work-orders/summary?stage=completed&type=corrective')->json('data.filtered');
    expect($filtered['count'])->toBe($completed->json('meta.total'))
        ->and($filtered['value_cents'])->toBe(array_sum(array_map(fn (array $o): int => $o['labor_cost_cents'] + array_sum(array_map(fn (array $p): int => (int) round((float) $p['quantity'] * $p['unit_cost_cents']), $o['parts'])) + ($o['parts'] === [] ? $o['parts_cost_cents'] : 0), $rows)));

    // Text matches the reference, title, technician, vendor or the plate.
    $vehicle = $this->getJson('/api/v1/vehicles?q=NCT9034')->json('data.0.id');
    $plate = $this->getJson('/api/v1/work-orders?q=nct%209034&per_page=100')->json('data');
    expect($plate)->not->toBeEmpty()
        ->and(array_values(array_unique(array_column($plate, 'vehicle_id'))))->toBe([$vehicle]);
    $this->getJson('/api/v1/work-orders?q=WO-2026-0200')->assertJsonPath('meta.total', 1);
});

it('keeps the totals to the caller\'s scope', function () {
    Sanctum::actingAs($this->world->user('fleet@northwind.ph'));

    $summary = $this->getJson('/api/v1/work-orders/summary')->assertOk()->json('data.buckets');
    expect($summary['all'])->toBe(asSystem(fn () => WorkOrder::query()->where('customer_account_id', $this->world->id('fc-northwind'))->count()));
});

it('releases a vehicle\'s finished jobs together, or none of them', function () {
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $list = $this->getJson('/api/v1/shop/ready-for-collection')->assertOk();
    $ready = $list->json('data.*.id');
    expect(count($ready))->toBeGreaterThan(2)
        // Labelled for the counter, as on /shop/home.
        ->and($list->json('data.0.vehicle.plate_number'))->toBeString()
        ->and($list->json('data.0.customer_name'))->toBeString();
    $open = $this->getJson('/api/v1/work-orders?stage=active&per_page=1')->json('data.0.id');

    // One order not collectable: nothing is collected.
    $this->postJson('/api/v1/work-orders/collect', ['work_order_ids' => [$ready[0], $open]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_transition');
    expect(asSystem(fn () => WorkOrder::query()->findOrFail($ready[0])->collected_at))->toBeNull();

    $this->postJson('/api/v1/work-orders/collect', ['work_order_ids' => [$ready[0], $ready[1]]])
        ->assertOk()
        ->assertJsonPath('data.collected', 2)
        ->assertJsonPath('data.work_orders.0.lifecycle_stage', 'completed');

    // Another organization's order is missing, not forbidden.
    $this->postJson('/api/v1/work-orders/collect', ['work_order_ids' => [$this->world->id('rival:work-order')]])->assertNotFound();

    // Portal users cannot release jobs.
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    expect($this->postJson('/api/v1/work-orders/collect', ['work_order_ids' => [$ready[2]]])->getStatusCode())->toBeIn([403, 404]);
});
