<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * A customer account's own spare parts (not shop inventory): SKU unique
 * within the account, stock set once and then moved only by receiving
 * purchase orders, usages naming the tasks that consume each part.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

function partsSignIn(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

it('adds a part to the portal user\'s own account, with its usages', function () {
    partsSignIn('donmiguel@mekanikomor.ph');

    $response = $this->postJson('/api/v1/fleet-parts', [
        'sku' => ' WIPER-22 ',
        'name' => 'Wiper blade 22"',
        'category' => 'other',
        'unit' => 'piece',
        'unit_cost_cents' => 45000,
        'current_stock' => 4,
        'reorder_point' => 2,
        'preferred_vendor' => 'Bridgestone Tire Center',
        'lead_time_days' => 3,
        'usages' => [['service_task_id' => $this->world->id('task:safety-inspection'), 'quantity_per_service' => 2]],
    ])->assertCreated();

    expect($response->json('data'))->toMatchArray([
        'customer_account_id' => $this->world->id('fc-actimed'),
        'sku' => 'WIPER-22',
        'current_stock' => 4,
        'needs_reorder' => false,
        'usages' => [['service_task_id' => $this->world->id('task:safety-inspection'), 'quantity_per_service' => 2]],
    ]);
    expect(asSystem(fn () => AuditLog::query()->where('entity_type', 'fleet_part')->where('action', 'created')->count()))->toBe(1);

    // SKUs are unique within an account.
    $this->postJson('/api/v1/fleet-parts', ['sku' => 'WIPER-22', 'name' => 'Again', 'unit_cost_cents' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.sku.0', 'This account already has a part with that SKU.');
});

it('makes staff name the account, and refuses one outside their reach', function () {
    partsSignIn('owner@mekanikomore.ph');
    $this->postJson('/api/v1/fleet-parts', ['sku' => 'X1', 'name' => 'X', 'unit_cost_cents' => 100])->assertUnprocessable();
    $this->postJson('/api/v1/fleet-parts', ['customer_account_id' => $this->world->id('rival:account'), 'sku' => 'X1', 'name' => 'X', 'unit_cost_cents' => 100])->assertNotFound();
    $this->postJson('/api/v1/fleet-parts', ['customer_account_id' => $this->world->id('fc-northwind'), 'sku' => 'X1', 'name' => 'X', 'unit_cost_cents' => 100])
        ->assertCreated()
        ->assertJsonPath('data.customer_account_id', $this->world->id('fc-northwind'));
});

it('never edits stock or moves a part between accounts', function () {
    partsSignIn('donmiguel@mekanikomor.ph');
    $uri = '/api/v1/fleet-parts/'.$this->world->id('part:fc-actimed:p-oil-filter');

    $this->patchJson($uri, ['current_stock' => 500])->assertUnprocessable();
    $this->patchJson($uri, ['customer_account_id' => $this->world->id('fc-northwind')])->assertUnprocessable();
    $this->patchJson($uri, ['reorder_point' => 2, 'usages' => []])
        ->assertOk()
        ->assertJsonPath('data.reorder_point', 2)
        ->assertJsonPath('data.needs_reorder', false)
        ->assertJsonPath('data.usages', []);
});

it('keeps a part that is on a purchase order', function () {
    partsSignIn('donmiguel@mekanikomor.ph');

    $this->deleteJson('/api/v1/fleet-parts/'.$this->world->id('part:fc-actimed:p-brake-pads'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
    $this->deleteJson('/api/v1/fleet-parts/'.$this->world->id('part:fc-actimed:p-oil-filter'))->assertNoContent();
    $this->getJson('/api/v1/fleet-parts/'.$this->world->id('part:fc-actimed:p-oil-filter'))->assertNotFound();
});

it('needs settings:manage to change the catalogue, and keeps each account to its own', function () {
    partsSignIn('viewer@mekanikomore.ph');
    $this->getJson('/api/v1/fleet-parts')->assertOk();
    $this->postJson('/api/v1/fleet-parts', ['sku' => 'X1', 'name' => 'X', 'unit_cost_cents' => 100])->assertForbidden();

    partsSignIn('fleet@northwind.ph');
    $this->getJson('/api/v1/fleet-parts/'.$this->world->id('part:fc-actimed:p-oil-filter'))->assertNotFound();
    expect(array_unique(array_column($this->getJson('/api/v1/fleet-parts')->json('data'), 'customer_account_id')))
        ->toBe([$this->world->id('fc-northwind')]);
});

it('forecasts for one account: staff name it, portal users get their own', function () {
    partsSignIn('owner@mekanikomore.ph');
    $this->getJson('/api/v1/demand-forecast')->assertUnprocessable();
    $this->getJson('/api/v1/demand-forecast?customer_account_id='.$this->world->id('rival:account'))->assertNotFound();

    partsSignIn('donmiguel@mekanikomor.ph');
    $data = $this->getJson('/api/v1/demand-forecast?horizon_weeks=6')->assertOk()->json('data');
    expect($data['customer_account_id'])->toBe($this->world->id('fc-actimed'))
        ->and($data['can_raise'])->toBeTrue()
        ->and($data['totals'])->toBe(['parts' => count($data['rows']), 'with_shortfall' => 2, 'lead_time_risks' => 2, 'estimated_cost_cents' => 186000]);

    partsSignIn('viewer@mekanikomore.ph');
    $this->getJson('/api/v1/demand-forecast')->assertOk()->assertJsonPath('data.can_raise', false);
});
