<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Consent;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

beforeEach(function () {
    $this->world = World::build();
});

function openAccount(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/customer-accounts', $overrides + [
        'account_type' => 'individual',
        'first_name' => 'Rosa',
        'last_name' => 'Manalo',
        'mobile' => '+639171234567',
        'consents' => [
            ['purpose' => 'service_records', 'granted' => true, 'channel' => 'in_person', 'evidence' => 'Signed job card #1043'],
            ['purpose' => 'marketing', 'granted' => false, 'channel' => 'in_person'],
        ],
    ]);
}

it('opens a walk-in account with its consents in one transaction', function () {
    Sanctum::actingAs($this->world->user('cashier@mekanikomore.ph'));

    $id = openAccount()
        ->assertCreated()
        ->assertJsonPath('data.display_name', 'Rosa Manalo')
        ->assertJsonPath('data.account_type', 'individual')
        ->assertJsonPath('data.approval_threshold_overrides', null)
        ->json('data.id');

    $this->getJson("/api/v1/customer-accounts/{$id}/consents/current")
        ->assertOk()
        ->assertJsonPath('data.account.service_records.granted', true)
        ->assertJsonPath('data.account.service_records.evidence', 'Signed job card #1043')
        ->assertJsonPath('data.account.marketing.granted', false)
        ->assertJsonPath('data.account.vehicle_history_sharing', null);

    expect(asSystem(fn () => AuditLog::query()->where('customer_account_id', $id)->pluck('action')->sort()->values()->all()))
        ->toBe(['created', 'granted', 'withdrawn']);
});

it('refuses to open an account without service_records consent', function (array $consents) {
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));

    openAccount(['consents' => $consents])->assertStatus(422);
})->with([
    'missing' => [[['purpose' => 'marketing', 'granted' => true, 'channel' => 'in_person']]],
    'refused' => [[['purpose' => 'service_records', 'granted' => false, 'channel' => 'in_person']]],
    'none' => [[]],
]);

it('keeps account opening with staff who hold customer:manage', function (string $who) {
    Sanctum::actingAs($this->world->user($who));

    openAccount()->assertForbidden();
})->with(['bay@mekanikomore.ph', 'donmiguel@mekanikomor.ph']);

it('stores a sparse override, keeping null (inherit) distinct from {}', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $empty = openAccount(['approval_threshold_overrides' => (object) []])->assertCreated();
    expect($empty->getContent())->toContain('"approval_threshold_overrides":{}');
    expect(DB::table('customer_accounts')->where('id', $empty->json('data.id'))->value('approval_threshold_overrides'))->toBe('{}');

    $id = $this->world->id('fc-sagrada');
    expect($this->getJson("/api/v1/customer-accounts/{$id}")->json('data.approval_threshold_overrides'))
        ->toEqual(['auto_approve_under_cents' => 0, 'variance_threshold_pct' => 5]);

    $this->patchJson("/api/v1/customer-accounts/{$id}", ['approval_threshold_overrides' => null])
        ->assertOk()->assertJsonPath('data.approval_threshold_overrides', null);

    $this->patchJson("/api/v1/customer-accounts/{$id}", ['approval_threshold_overrides' => ['not_a_band' => 1]])
        ->assertStatus(422);
});

it('converts the demo seed\'s peso overrides to centavos', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    // jsonb keeps its own key order; the API promises the keys, not their order.
    expect($this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-northwind'))->json('data.approval_threshold_overrides'))
        ->toEqual(['auto_approve_under_cents' => 1200000, 'sla_hours' => 2]);
});

it('lets a portal fleet manager edit their own contact details but not the shop\'s terms', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $actimed = $this->world->id('fc-actimed');

    $this->patchJson("/api/v1/customer-accounts/{$actimed}", ['contact_email' => 'fleet-desk@actimed.ph'])->assertOk();
    $this->patchJson("/api/v1/customer-accounts/{$actimed}", ['payment_terms_days' => 90])->assertStatus(422);
    $this->getJson("/api/v1/customer-accounts/{$actimed}")->assertOk()->assertJsonMissingPath('data.notes');
    $this->patchJson('/api/v1/customer-accounts/'.$this->world->id('fc-northwind'), ['contact_email' => 'x@y.ph'])->assertNotFound();
});

it('lets a viewer read but not edit their account', function () {
    Sanctum::actingAs($this->world->user('viewer@mekanikomore.ph'));

    $this->patchJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed'), ['contact_name' => 'x'])
        ->assertForbidden()
        ->assertJsonPath('error.message', "Authorised Viewer doesn't have permission to manage customer accounts.");
});

it('keeps one primary contact per account', function () {
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $actimed = $this->world->id('fc-actimed');

    $this->postJson("/api/v1/customer-accounts/{$actimed}/contacts", ['name' => 'Second Person', 'is_primary' => true])->assertCreated();

    $primaries = collect($this->getJson("/api/v1/customer-accounts/{$actimed}/contacts")->json('data'))->where('is_primary', true);
    expect($primaries->pluck('name')->all())->toBe(['Second Person']);
});

it('will not delete a contact with consent decisions on record', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->deleteJson('/api/v1/customer-accounts/'.$this->world->id('walk-in').'/contacts/'.$this->world->id('walk-in:contact'))
        ->assertStatus(409);
});

it('scopes a nested contact to its own account', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed').'/contacts/'.$this->world->id('walk-in:contact'))
        ->assertNotFound();
});

it('records consent through the right channel for who is recording it', function () {
    $actimed = $this->world->id('fc-actimed');

    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $this->postJson("/api/v1/customer-accounts/{$actimed}/consents", ['purpose' => 'marketing', 'granted' => true, 'channel' => 'phone'])->assertStatus(422);
    $this->postJson("/api/v1/customer-accounts/{$actimed}/consents", ['purpose' => 'marketing', 'granted' => true, 'channel' => 'portal'])
        ->assertCreated()->assertJsonPath('data.captured_by', null);

    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $this->postJson("/api/v1/customer-accounts/{$actimed}/consents", ['purpose' => 'marketing', 'granted' => false, 'channel' => 'portal'])->assertStatus(422);
    $this->postJson("/api/v1/customer-accounts/{$actimed}/consents", ['purpose' => 'marketing', 'granted' => false, 'channel' => 'import'])->assertStatus(422);
    $this->postJson("/api/v1/customer-accounts/{$actimed}/consents", ['purpose' => 'marketing', 'granted' => false, 'channel' => 'sms'])
        ->assertCreated()->assertJsonPath('data.captured_by', $this->world->id('advisor@mekanikomore.ph'));

    $this->getJson("/api/v1/customer-accounts/{$actimed}/consents/current")->assertJsonPath('data.account.marketing.granted', false);
    expect(asSystem(fn () => Consent::query()->where('customer_account_id', $actimed)->where('purpose', 'marketing')->count()))->toBe(2);
});

it('refuses a contact-level consent naming another account\'s contact', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->postJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed').'/consents', [
        'purpose' => 'marketing', 'granted' => true, 'channel' => 'email', 'contact_id' => $this->world->id('walk-in:contact'),
    ])->assertStatus(422)->assertJsonPath('error.details.fields.contact_id.0', 'That contact does not belong to this customer account.');
});

it('refuses a consent captured in the future', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->postJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed').'/consents', [
        'purpose' => 'marketing', 'granted' => true, 'channel' => 'email', 'captured_at' => now()->addDay()->toIso8601String(),
    ])->assertStatus(422);
});

it('filters and searches the account list', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->getJson('/api/v1/customer-accounts?account_type=individual')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.display_name', 'Jun Dela Cruz');
    $this->getJson('/api/v1/customer-accounts?q=north')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.display_name', 'Northwind Logistics');
    $this->getJson('/api/v1/customer-accounts?q=%25')->assertJsonPath('meta.total', 0);
});
