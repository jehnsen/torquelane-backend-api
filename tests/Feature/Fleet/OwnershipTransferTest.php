<?php

declare(strict_types=1);

use App\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Service history stays with the VEHICLE; documents stay with the account
 * they were filed under. A new owner's portal users see the vehicle, its
 * readings and its maintenance state, but not the previous owner's
 * documents; the previous owner loses the vehicle but keeps their papers.
 * (Work orders and invoices follow the same rule when they arrive.)
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->vehicle = $this->world->id('veh-001');
    $this->actimedDocs = asSystem(fn () => Document::query()->where('vehicle_id', $this->vehicle)->pluck('id')->all());
});

function transfer(string $vehicle, string $to): TestResponse
{
    return test()->postJson("/api/v1/vehicles/{$vehicle}/transfer", ['customer_account_id' => $to]);
}

it('moves the vehicle, keeps its service history, and leaves the papers with the old owner', function () {
    expect($this->actimedDocs)->not->toBeEmpty();

    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    transfer($this->vehicle, $this->world->id('fc-northwind'))
        ->assertOk()
        ->assertJsonPath('data.customer_account_id', $this->world->id('fc-northwind'));

    // The new owner sees the vehicle and its history…
    Sanctum::actingAs($this->world->user('fleet@northwind.ph'));
    $this->getJson("/api/v1/vehicles/{$this->vehicle}")->assertOk()->assertJsonPath('data.odometer.value', 45600);
    $this->getJson("/api/v1/vehicles/{$this->vehicle}/readings")->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson("/api/v1/vehicles/{$this->vehicle}/health")->assertOk()->assertJsonCount(12, 'data.items');
    // …but none of the previous owner's documents.
    foreach ($this->actimedDocs as $doc) {
        $this->getJson("/api/v1/documents/{$doc}")->assertNotFound();
    }
    $this->getJson("/api/v1/documents?vehicle_id={$this->vehicle}")->assertOk()->assertJsonPath('meta.total', 0);
    $alertIds = array_column($this->getJson('/api/v1/alerts')->json('data'), 'id');
    foreach ($this->actimedDocs as $doc) {
        expect($alertIds)->not->toContain("doc:{$doc}");
    }

    // The previous owner loses the vehicle, keeps their own papers.
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $this->getJson("/api/v1/vehicles/{$this->vehicle}")->assertNotFound();
    $this->getJson("/api/v1/documents/{$this->actimedDocs[0]}")->assertOk();

    // Staff see both ownerships.
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $ownerships = $this->getJson("/api/v1/vehicles/{$this->vehicle}/ownerships")->assertOk()->json('data');
    expect(array_column($ownerships, 'customer_account_id'))->toBe([$this->world->id('fc-northwind'), $this->world->id('fc-actimed')])
        ->and(array_column($ownerships, 'current'))->toBe([true, false])
        ->and($ownerships[1]['to_date'])->toBe('2026-10-08');
});

it('files documents uploaded after the transfer under the new owner', function () {
    Storage::fake('documents');
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    transfer($this->vehicle, $this->world->id('fc-northwind'))->assertOk();

    $this->post('/api/v1/documents', [
        'vehicle_id' => $this->vehicle,
        'kind' => 'lto_registration',
        'file' => UploadedFile::fake()->create('or-cr.pdf', 40, 'application/pdf'),
        'expires_on' => '2027-10-01',
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.customer_account_id', $this->world->id('fc-northwind'));
});

it('refuses a transfer to a suspended account, to the same account, or by a portal user', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    transfer($this->vehicle, $this->world->id('fc-bayani'))->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
    transfer($this->vehicle, $this->world->id('fc-actimed'))->assertStatus(422);
    transfer($this->vehicle, $this->world->id('rival:account'))->assertNotFound();

    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    transfer($this->vehicle, $this->world->id('fc-northwind'))->assertForbidden();
});
