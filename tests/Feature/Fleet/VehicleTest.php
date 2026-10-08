<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\MeterReading;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

function registerVehicle(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/vehicles', $overrides + [
        'plate_number' => 'NEW 1234',
        'make' => 'Toyota',
        'model' => 'Vios',
        'vehicle_class' => 'sedan',
        'size_class' => 'medium',
        'odometer' => ['value' => 12000, 'read_on' => '2026-10-08'],
    ]);
}

it('registers a vehicle with its first reading and computed state', function () {
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));

    $response = registerVehicle(['customer_account_id' => $this->world->id('fc-actimed')])
        ->assertCreated()
        ->assertJsonPath('data.plate_normalized', 'NEW1234')
        ->assertJsonPath('data.odometer.value', 12000)
        ->assertJsonPath('data.odometer.label', '12,000 km')
        // One reading: no rate yet, so the calendar limit governs.
        ->assertJsonPath('data.odometer.avg_daily_km', 0)
        ->assertJsonPath('data.odometer.stale', false)
        ->assertJsonPath('data.lto_renewal_month', 'April')
        ->assertJsonPath('data.compliance_status', 'ok');

    // No history: every task is assumed exactly one interval old on both
    // limits, i.e. due today (0 km, 0 days left): due_soon, as in ../web.
    expect($response->json('data.pms.status'))->toBe('due_soon')
        ->and($response->json('data.pms.next_item.due_label'))->toBe('Due today');
    expect(asSystem(fn () => AuditLog::query()->where('entity_id', $response->json('data.id'))->value('action')))->toBe('created');
});

it('lets a portal fleet manager register to their own account only', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    registerVehicle()->assertCreated()->assertJsonPath('data.customer_account_id', $this->world->id('fc-actimed'));
    registerVehicle(['plate_number' => 'XYZ 9', 'customer_account_id' => $this->world->id('fc-northwind')])->assertNotFound();
});

it('refuses vehicles for a suspended account, and roles without vehicle:manage', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    registerVehicle(['customer_account_id' => $this->world->id('fc-bayani')])->assertForbidden()->assertJsonPath('error.code', 'account_suspended');

    Sanctum::actingAs($this->world->user('bay@mekanikomore.ph'));
    registerVehicle(['customer_account_id' => $this->world->id('fc-actimed')])->assertForbidden();
});

it('treats plates as one when they differ only in case, spaces or dashes', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    registerVehicle(['customer_account_id' => $this->world->id('fc-actimed'), 'plate_number' => 'nba-4821'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.plate_number.0', 'Another vehicle already has this plate.');

    // Another organization's identical plate is no conflict.
    $this->getJson('/api/v1/vehicles?q=nba-4821')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->world->id('veh-001'));
});

it('frees the plate when a vehicle is archived, and never deletes it', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $vehicle = $this->world->id('veh-001');

    $this->deleteJson("/api/v1/vehicles/{$vehicle}")->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
    registerVehicle(['customer_account_id' => $this->world->id('fc-northwind'), 'plate_number' => 'NBA 4821'])->assertCreated();
    $this->getJson("/api/v1/vehicles/{$vehicle}")->assertOk();
    $this->getJson('/api/v1/vehicles?include_archived=1&q=NBA4821')->assertJsonPath('meta.total', 2);
});

it('never changes the odometer or the owner through an edit', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $vehicle = $this->world->id('veh-001');

    $this->patchJson("/api/v1/vehicles/{$vehicle}", ['odometer' => ['value' => 1]])->assertStatus(422);
    $this->patchJson("/api/v1/vehicles/{$vehicle}", ['customer_account_id' => $this->world->id('fc-northwind')])->assertStatus(422);
    $this->patchJson("/api/v1/vehicles/{$vehicle}", ['color' => 'Red', 'size_class' => 'large'])->assertOk()->assertJsonPath('data.color', 'Red');
});

it('leaves PMS out where repair_pms is not active, and refuses the PMS views', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $vehicle = $this->world->id('veh-001');

    $this->getJson("/api/v1/vehicles/{$vehicle}")->assertOk()->assertJsonPath('data.pms', null);
    $this->getJson("/api/v1/vehicles/{$vehicle}/health")->assertForbidden()->assertJsonPath('error.code', 'module_disabled');
    $this->getJson('/api/v1/fleet/summary')->assertForbidden()->assertJsonPath('error.code', 'module_disabled');
    $this->getJson('/api/v1/service-tasks')->assertForbidden()->assertJsonPath('error.code', 'module_disabled');
    expect(array_filter(array_column($this->getJson('/api/v1/alerts')->json('data'), 'kind'), fn ($k) => str_starts_with($k, 'pms_')))->toBe([]);
});

it('keeps readings append-only at the database', function () {
    DB::transaction(fn () => DB::table('meter_readings')->limit(1)->update(['value' => 1]));
})->throws(QueryException::class, '23001');

it('reports a stale odometer and its age', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    // veh-002 was last read on 2026-09-17: 21 days before the frozen clock.
    $this->getJson('/api/v1/vehicles/'.$this->world->id('veh-002'))
        ->assertJsonPath('data.odometer.age_days', 21)
        ->assertJsonPath('data.odometer.stale', true);
    expect(asSystem(fn () => MeterReading::query()->where('vehicle_id', $this->world->id('veh-002'))->count()))->toBe(2);
});
