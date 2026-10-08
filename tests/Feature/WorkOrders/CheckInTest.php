<?php

declare(strict_types=1);

use App\Models\CustomerAccount;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The counter. veh-001 is Actimed's NBA 4821 (VIN 0S8P71EMTNHABZ030),
 * 45,600 km read 2026-10-08; a reading goes stale after 14 days.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
});

it('waits for enough typed to mean anything', function () {
    $this->getJson('/api/v1/check-in/lookup?q=NB')->assertOk()->assertJsonPath('data.outcome', 'idle')->assertJsonPath('data.vehicle', null);
});

it('hydrates the form from a plate however it was typed, with work already due', function () {
    $this->getJson('/api/v1/check-in/lookup?q='.urlencode('nba-4821'))
        ->assertOk()
        ->assertJsonPath('data.outcome', 'existing')
        ->assertJsonPath('data.matched_on', 'plate')
        ->assertJsonPath('data.vehicle.id', $this->world->id('veh-001'))
        ->assertJsonPath('data.customer.id', $this->world->id('fc-actimed'))
        ->assertJsonPath('data.form.plate_number', 'NBA 4821')
        ->assertJsonPath('data.form.customer_name', 'Actimed')
        ->assertJsonPath('data.form.odometer', 45600)
        ->assertJsonPath('data.form.odometer_needs_confirmation', false)
        ->assertJsonPath('data.odometer_stale', false)
        ->assertJsonPath('data.suggested_work', fn (array $items) => count($items) <= 5);
});

it('matches a VIN when no plate does', function () {
    $this->getJson('/api/v1/check-in/lookup?q=0s8p71emtnhabz030')->assertJsonPath('data.outcome', 'existing')->assertJsonPath('data.matched_on', 'vin');
});

it('never pre-fills a stale odometer', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02T10:00:00+08:00'));

    $this->getJson('/api/v1/check-in/lookup?q=NBA4821')
        ->assertOk()
        ->assertJsonPath('data.odometer_stale', true)
        ->assertJsonPath('data.odometer_age_days', 25)
        ->assertJsonPath('data.last_odometer', 45600)
        ->assertJsonPath('data.last_odometer_read_on', '2026-10-08')
        ->assertJsonPath('data.form.odometer', null)
        ->assertJsonPath('data.form.odometer_needs_confirmation', true);
});

it('carries an unknown identifier into a new-vehicle form', function () {
    $this->getJson('/api/v1/check-in/lookup?q=xyz-9999')
        ->assertJsonPath('data.outcome', 'new')
        ->assertJsonPath('data.form.plate_number', 'XYZ9999')
        ->assertJsonPath('data.form.vin', '');

    $this->getJson('/api/v1/check-in/lookup?q=1HGCM82633A004352')
        ->assertJsonPath('data.form.plate_number', '')
        ->assertJsonPath('data.form.vin', '1HGCM82633A004352');
});

it('looks up only what the caller may see: a sibling account\'s plate reads as new', function () {
    Sanctum::actingAs($this->world->user('fleet@northwind.ph'));

    $this->getJson('/api/v1/check-in/lookup?q=NBA4821')
        ->assertOk()
        ->assertJsonPath('data.outcome', 'new')
        ->assertJsonPath('data.vehicle', null)
        ->assertJsonPath('data.customer', null);
});

/**
 * @return array<string, mixed>
 */
function counterBody(string $plate): array
{
    return [
        'customer' => ['account_type' => 'individual', 'first_name' => 'Ramon', 'last_name' => 'Cruz', 'mobile' => '+639171234567'],
        'consents' => [['purpose' => 'service_records', 'granted' => true, 'channel' => 'in_person']],
        'vehicle' => ['plate_number' => $plate, 'make' => 'Toyota', 'model' => 'Vios', 'year' => 2019],
        'odometer' => 81230,
    ];
}

it('registers a walk-in customer and vehicle at the counter in one go', function () {
    $this->postJson('/api/v1/check-in', counterBody('ABC 1234'))
        ->assertCreated()
        ->assertJsonPath('data.customer.display_name', 'Ramon Cruz')
        ->assertJsonPath('data.vehicle.plate_number', 'ABC 1234')
        ->assertJsonPath('data.vehicle.odometer.value', 81230);

    $this->getJson('/api/v1/check-in/lookup?q=ABC1234')->assertJsonPath('data.outcome', 'existing');
});

it('leaves no orphan customer when the vehicle is refused', function () {
    $before = asSystem(fn () => CustomerAccount::query()->count());

    // NBA 4821 is already registered.
    $this->postJson('/api/v1/check-in', counterBody('NBA 4821'))->assertUnprocessable();

    expect(asSystem(fn () => CustomerAccount::query()->count()))->toBe($before);
});

it('needs both customer and vehicle rights to register at the counter', function () {
    Sanctum::actingAs($this->world->user('cashier@mekanikomore.ph'));
    $this->postJson('/api/v1/check-in', counterBody('ABC 1234'))->assertForbidden();

    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $this->postJson('/api/v1/check-in', counterBody('ABC 1234'))->assertForbidden();
});

it('requires the service-records consent to open the account', function () {
    $body = counterBody('ABC 1234');
    $body['consents'] = [['purpose' => 'marketing', 'granted' => true, 'channel' => 'in_person']];

    $this->postJson('/api/v1/check-in', $body)->assertUnprocessable();
});
