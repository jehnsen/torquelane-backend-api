<?php

declare(strict_types=1);

use App\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    Storage::fake('documents');
    $this->world = World::build();
});

function upload(array $fields): TestResponse
{
    return test()->post('/api/v1/documents', $fields + [
        'file' => UploadedFile::fake()->create('ctpl-policy.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json']);
}

it('stores a file privately and serves it only through a short-lived signed URL', function () {
    Sanctum::actingAs($this->world->user('ops@mekanikomore.ph'));

    $document = upload(['vehicle_id' => $this->world->id('veh-001'), 'kind' => 'ctpl', 'expires_on' => '2026-11-01'])
        ->assertCreated()
        ->assertJsonPath('data.customer_account_id', $this->world->id('fc-actimed'))
        ->assertJsonPath('data.has_file', true)
        // 24 days out: inside the 30-day badge window.
        ->assertJsonPath('data.expiry_status', 'expiring')
        ->assertJsonMissingPath('data.storage_path')
        ->json('data');

    $path = asSystem(fn () => Document::query()->findOrFail($document['id'])->storage_path);
    Storage::disk('documents')->assertExists($path);

    $link = $this->getJson("/api/v1/documents/{$document['id']}/download")->assertOk()->json('data');
    $this->get($link['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Tampered or expired signatures are refused.
    $this->get($link['url'].'x')->assertForbidden();
    $this->travel(61)->seconds();
    $this->get($link['url'])->assertForbidden();

    // The new document raises its own expiry alert (inside 45 days).
    expect(array_column($this->getJson('/api/v1/alerts')->json('data'), 'id'))->toContain('doc:'.$document['id']);
});

it('takes an expiry only on renewal kinds', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    upload(['vehicle_id' => $this->world->id('veh-001'), 'kind' => 'invoice', 'expires_on' => '2027-01-01'])->assertStatus(422);
    upload(['vehicle_id' => $this->world->id('veh-001'), 'kind' => 'emission_test', 'expires_on' => '2027-01-01'])->assertCreated();
    upload(['customer_account_id' => $this->world->id('fc-sagrada'), 'kind' => 'other'])->assertCreated()->assertJsonPath('data.vehicle_id', null);
});

it('refuses other accounts\' vehicles and documents, and files the portal user cannot upload', function () {
    Sanctum::actingAs($this->world->user('fleet@northwind.ph'));

    upload(['vehicle_id' => $this->world->id('veh-001'), 'kind' => 'photo'])->assertNotFound();
    $actimedDoc = asSystem(fn () => Document::query()->where('customer_account_id', $this->world->id('fc-actimed'))->value('id'));
    $this->getJson("/api/v1/documents/{$actimedDoc}/download")->assertNotFound();

    Sanctum::actingAs($this->world->user('viewer@mekanikomore.ph'));
    upload(['vehicle_id' => $this->world->id('veh-001'), 'kind' => 'photo'])->assertForbidden();
});

it('has no download for an imported paper record', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $seeded = $this->world->id('doc-0065');

    $this->getJson("/api/v1/documents/{$seeded}")->assertOk()->assertJsonPath('data.has_file', false)->assertJsonPath('data.uploaded_by_name', 'Marisol Bautista');
    $this->getJson("/api/v1/documents/{$seeded}/download")->assertStatus(409);
});

it('deletes the row and then the file', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $id = upload(['vehicle_id' => $this->world->id('veh-001'), 'kind' => 'photo'])->assertCreated()->json('data.id');
    $path = asSystem(fn () => Document::query()->findOrFail($id)->storage_path);

    $this->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

    Storage::disk('documents')->assertMissing($path);
    $this->getJson("/api/v1/documents/{$id}")->assertNotFound();
});

it('attaches a document to a work order, filed under the order\'s account and vehicle', function () {
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $order = $this->world->id('wo-0079');

    $attached = $this->getJson("/api/v1/documents?work_order_id={$order}&per_page=100")->assertOk()->json('data');
    expect($attached)->not->toBeEmpty()
        ->and(array_unique(array_column($attached, 'work_order_id')))->toBe([$order]);

    upload(['work_order_id' => $order, 'kind' => 'invoice'])
        ->assertCreated()
        ->assertJsonPath('data.work_order_id', $order)
        ->assertJsonPath('data.vehicle_id', $this->world->id('veh-007'))
        ->assertJsonPath('data.customer_account_id', $this->world->id('fc-actimed'));
    upload(['work_order_id' => $order, 'vehicle_id' => $this->world->id('veh-001'), 'kind' => 'invoice'])->assertUnprocessable();
    upload(['work_order_id' => $this->world->id('rival:work-order'), 'kind' => 'invoice'])->assertNotFound();

    // Another account's portal user cannot attach to it, nor see it.
    Sanctum::actingAs($this->world->user('fleet@northwind.ph'));
    upload(['work_order_id' => $order, 'kind' => 'invoice'])->assertNotFound();
    $this->getJson("/api/v1/documents?work_order_id={$order}")->assertOk()->assertJsonPath('meta.total', 0);
});
