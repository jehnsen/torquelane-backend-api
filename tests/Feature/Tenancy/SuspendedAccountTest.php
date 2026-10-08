<?php

declare(strict_types=1);

use App\Exceptions\AccountSuspendedException;
use App\Models\CustomerAccount;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * RULE CHANGE from ../web (deliberate). The frontend resolved a suspended
 * client to no scope on the client side, and its database hid it from the
 * provider side too. In the API:
 *  - portal users of a suspended account are denied (403 account_suspended);
 *  - staff can still READ a suspended account's records (collections,
 *    history) and keep them accurate;
 *  - staff cannot start NEW WORK for it (CustomerAccountPolicy::createWorkFor).
 */

beforeEach(function () {
    $this->world = World::build();
    $this->bayani = $this->world->id('fc-bayani');
});

it('denies a suspended account\'s portal user on every route, with the reason', function (string $path) {
    Sanctum::actingAs($this->world->user('yard@bayanicon.ph'));

    $this->getJson($path)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_suspended')
        ->assertJsonPath('error.details.reason', 'account_suspended');
})->with(['/api/v1/me', '/api/v1/customer-accounts', '/api/v1/users']);

it('lets staff read a suspended account and everything beneath it', function () {
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));

    $this->getJson("/api/v1/customer-accounts/{$this->bayani}")->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->getJson("/api/v1/customer-accounts/{$this->bayani}/contacts")->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/customer-accounts/{$this->bayani}/consents")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/customer-accounts/{$this->bayani}/consents/current")->assertOk()->assertJsonPath('data.account.service_records.granted', true);
    $this->getJson('/api/v1/customer-accounts?status=suspended')->assertOk()->assertJsonPath('data.0.id', $this->bayani);
});

it('lets staff keep a suspended account accurate: contacts and consent withdrawals', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->patchJson("/api/v1/customer-accounts/{$this->bayani}", ['contact_email' => 'collections@bayanicon.ph'])->assertOk();
    $this->postJson("/api/v1/customer-accounts/{$this->bayani}/consents", ['purpose' => 'service_reminders', 'granted' => false, 'channel' => 'phone'])->assertCreated();
});

it('stops staff from starting new work for a suspended account', function () {
    $owner = $this->world->user('owner@mekanikomore.ph');
    $context = app(TenantContextResolver::class)->resolve($owner, null)->context;
    $account = asSystem(fn () => CustomerAccount::query()->findOrFail($this->bayani));
    $active = asSystem(fn () => CustomerAccount::query()->findOrFail($this->world->id('fc-actimed')));

    app(TenantManager::class)->actingAs($context, function () use ($owner, $account, $active): void {
        expect(Gate::forUser($owner)->allows('createWorkFor', $active))->toBeTrue();
        expect(fn () => Gate::forUser($owner)->authorize('createWorkFor', $account))->toThrow(AccountSuspendedException::class);
    });
});

it('refuses to add portal users to a suspended account', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->postJson('/api/v1/invitations', ['email' => 'new@bayanicon.ph', 'name' => 'New', 'role' => 'viewer', 'customer_account_id' => $this->bayani])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_suspended');
});

it('locks portal users out the moment their account is suspended, and back in on reactivation', function () {
    $northwind = $this->world->id('fc-northwind');
    $owner = $this->world->user('owner@mekanikomore.ph');
    $portal = $this->world->user('fleet@northwind.ph');

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/customer-accounts/{$northwind}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->postJson("/api/v1/customer-accounts/{$northwind}/suspend")->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    Sanctum::actingAs($portal);
    $this->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'account_suspended');

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/customer-accounts/{$northwind}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');

    Sanctum::actingAs($portal);
    $this->getJson('/api/v1/me')->assertOk();
});

it('lets only staff with settings:manage suspend an account', function (string $who, int $status) {
    Sanctum::actingAs($this->world->user($who));

    $this->postJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed').'/suspend')->assertStatus($status);
})->with([
    'advisor (no settings:manage)' => ['advisor@mekanikomore.ph', 403],
    'fleet manager (portal side)' => ['donmiguel@mekanikomor.ph', 403],
    'provider admin' => ['owner@mekanikomore.ph', 200],
]);
