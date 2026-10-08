<?php

declare(strict_types=1);

use App\Domain\Modules\Module;
use App\Exceptions\ModuleDisabledException;
use App\Models\WorkOrder;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

beforeEach(function () {
    $this->world = World::build();
});

// ------------------------------------------------------------ organization

it('lets only organization:manage change the organization profile', function (string $who, int $status) {
    Sanctum::actingAs($this->world->user($who));

    $this->patchJson('/api/v1/organization', ['tin' => '123-456-789'])->assertStatus($status);
})->with([
    'provider admin' => ['owner@mekanikomore.ph', 200],
    'branch manager' => ['manager.samahuzai@mekanikomore.ph', 403],
    'portal fleet manager' => ['donmiguel@mekanikomor.ph', 403],
]);

it('validates the BIR TIN format', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->patchJson('/api/v1/organization', ['tin' => '123456789'])->assertStatus(422);
});

// ---------------------------------------------------------------- branches

it('opens, edits and deletes an empty branch', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $id = $this->postJson('/api/v1/branches', ['name' => 'MekanikoMoR-Sta. Rosa', 'slug' => 'mekanikomor-sta-rosa', 'branch_code' => '002'])
        ->assertCreated()
        ->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.is_vat_registered', true)
        ->json('data.id');

    $this->postJson('/api/v1/branches', ['name' => 'Dup', 'slug' => 'mekanikomor-sta-rosa'])->assertStatus(422);
    $this->patchJson("/api/v1/branches/{$id}", ['prices_include_vat' => false])->assertOk()->assertJsonPath('data.prices_include_vat', false);
    $this->deleteJson("/api/v1/branches/{$id}")->assertNoContent();
});

it('refuses to delete a branch that still has bays or pinned staff', function (string $slug) {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->deleteJson('/api/v1/branches/'.$this->world->id($slug))->assertStatus(409)->assertJsonPath('error.code', 'conflict');
})->with(['mekanikomor-binan', 'samahuzai-binan']);

it('lets a branch manager edit their own branch only', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));

    $this->patchJson('/api/v1/branches/'.$this->world->id('samahuzai-binan'), ['brand_color' => '#123456'])->assertOk();
    $this->patchJson('/api/v1/branches/'.$this->world->id('mekanikomor-binan'), ['brand_color' => '#123456'])->assertNotFound();
    $this->postJson('/api/v1/branches', ['name' => 'Mine', 'slug' => 'mine'])->assertForbidden();
});

it('keeps branches from portal users', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $this->getJson('/api/v1/branches')->assertForbidden();
});

// ----------------------------------------------------------------- modules

it('activates a module in a branch only when the organization has it too', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $repair = $this->world->id('mekanikomor-binan');

    $this->putJson("/api/v1/branches/{$repair}/modules/pos", ['enabled' => true])
        ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.active', false);

    $this->putJson('/api/v1/modules/pos', ['enabled' => true])->assertOk()->assertJsonPath('data.organization_enabled', true);

    $this->getJson('/api/v1/me', ['X-Branch-Id' => $repair])->assertJsonPath('data.modules.active', ['repair_pms', 'pos']);

    $this->putJson('/api/v1/modules/repair_pms', ['enabled' => false])->assertOk();
    $this->getJson('/api/v1/me', ['X-Branch-Id' => $repair])->assertJsonPath('data.modules.active', ['pos']);

    $this->putJson('/api/v1/modules/teleportation', ['enabled' => true])->assertNotFound();
});

it('lists each module with its switches per reachable branch', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));

    $modules = collect($this->getJson('/api/v1/modules')->assertOk()->json('data'))->keyBy('module');

    expect($modules['detailing']['organization_enabled'])->toBeTrue()
        ->and($modules['detailing']['branches'])->toBe([['branch_id' => $this->world->id('samahuzai-binan'), 'enabled' => true, 'active' => true]])
        ->and($modules['repair_pms']['branches'][0]['active'])->toBeFalse();
});

it('refuses work in a branch whose module is off (module entitlement)', function () {
    $context = app(TenantContextResolver::class)->resolve($this->world->user('owner@mekanikomore.ph'), $this->world->id('samahuzai-binan'))->context;

    app(TenantManager::class)->actingAs($context, function (): void {
        app(ModuleGate::class)->ensure(Module::Detailing);
        app(ModuleGate::class)->ensure(Module::RepairPms);
    });
})->throws(ModuleDisabledException::class, 'Repair & PMS is not enabled for this branch.');

// ------------------------------------------------------------------- users

it('lets an admin change someone\'s role and pins, never their own', function () {
    $owner = $this->world->user('owner@mekanikomore.ph');
    Sanctum::actingAs($owner);
    $advisor = $this->world->id('advisor@mekanikomore.ph');
    $repair = $this->world->id('mekanikomor-binan');

    $this->patchJson("/api/v1/users/{$advisor}", ['role' => 'branch_manager', 'branch_ids' => [$repair]])
        ->assertOk()->assertJsonPath('data.role', 'branch_manager')->assertJsonPath('data.branch_ids', [$repair]);
    $this->patchJson("/api/v1/users/{$advisor}", ['role' => 'viewer'])->assertStatus(422);
    $this->patchJson("/api/v1/users/{$owner->id}", ['status' => 'disabled'])->assertForbidden();
});

it('stops a branch manager from escalating or reaching beyond their branches', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));

    // Unrestricted staff work in every branch, so they are beyond any branch manager.
    $this->patchJson('/api/v1/users/'.$this->world->id('advisor@mekanikomore.ph'), ['title' => 'x'])->assertForbidden();
    $this->patchJson('/api/v1/users/'.$this->world->id('owner@mekanikomore.ph'), ['title' => 'x'])->assertForbidden();
    $this->patchJson('/api/v1/users/'.$this->world->id('cashier@mekanikomore.ph'), ['title' => 'x'])->assertForbidden();
});

it('disables a user, who is then refused on every request', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $this->patchJson('/api/v1/users/'.$this->world->id('bay@mekanikomore.ph'), ['status' => 'disabled'])->assertOk();

    Sanctum::actingAs($this->world->user('bay@mekanikomore.ph'));
    $this->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.details.reason', 'user_disabled');
});

// ------------------------------------------------------- bays & technicians

it('manages bays and technicians inside allowed branches', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $detailing = $this->world->id('samahuzai-binan');

    $bay = $this->postJson('/api/v1/bays', ['branch_id' => $detailing, 'name' => 'Ceramic Bay', 'capacity_hours_per_day' => 7.5])
        ->assertCreated()->assertJsonPath('data.capacity_hours_per_day', '7.500')->json('data.id');

    $this->postJson('/api/v1/technicians', ['branch_id' => $detailing, 'name' => 'Nina Coating', 'skill_tags' => ['detailer'], 'home_bay_id' => $bay])
        ->assertCreated()->assertJsonPath('data.skill_tags', ['detailer']);

    $this->postJson('/api/v1/bays', ['branch_id' => $this->world->id('mekanikomor-binan'), 'name' => 'Not Mine'])->assertNotFound();
    $this->postJson('/api/v1/technicians', ['branch_id' => $detailing, 'name' => 'Cross Wired', 'home_bay_id' => $this->world->id('bay:bay-1')])
        ->assertStatus(422)->assertJsonPath('error.details.fields.home_bay_id.0', "The home bay must be in the technician's own branch.");
    $this->deleteJson("/api/v1/bays/{$bay}")->assertStatus(409);
});

it('keeps a technician or bay that work orders name, rather than failing in the database', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    [$technician, $bay] = asSystem(fn (): array => [
        WorkOrder::query()->whereNotNull('technician_id')->value('technician_id'),
        WorkOrder::query()->whereNotNull('bay_id')->value('bay_id'),
    ]);

    $this->deleteJson("/api/v1/technicians/{$technician}")
        ->assertStatus(409)
        ->assertJsonPath('error.message', 'This technician is on work orders. Mark them inactive instead.');
    $this->deleteJson("/api/v1/bays/{$bay}")->assertStatus(409);
});

it('keeps bays from staff without settings:manage and from portal users', function (string $who, int $status) {
    Sanctum::actingAs($this->world->user($who));

    $this->postJson('/api/v1/bays', ['branch_id' => $this->world->id('mekanikomor-binan'), 'name' => 'X'])->assertStatus($status);
})->with([
    'advisor' => ['advisor@mekanikomore.ph', 403],
    'portal fleet manager' => ['donmiguel@mekanikomor.ph', 403],
]);
