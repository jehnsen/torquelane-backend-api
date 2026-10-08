<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Readings are gated by the ported odometer validation (the frontend's rules
 * and messages, golden-tested), and the vehicle's odometer and daily rate are
 * derived from them. veh-001 in the seed: 45,600 km read 2026-10-08, 48 km/day.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11T10:00:00+08:00'));
    $this->world = World::build();
    $this->vehicle = $this->world->id('veh-001');
    Sanctum::actingAs($this->world->user('tech@mekanikomore.ph'));
});

function read(string $vehicle, array $body): TestResponse
{
    return test()->postJson("/api/v1/vehicles/{$vehicle}/readings", $body);
}

it('refuses a reading below the current one, with the frontend\'s message', function () {
    read($this->vehicle, ['value' => 45000])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.value.0', 'Cannot be lower than the last recorded reading of 45,600 km on 08 Oct 2026.');
});

it('holds an implausible reading until it is confirmed', function () {
    // 3 days at 48 km/day ≈ 144 km; 2,000 km implies 667 km/day (> 3×).
    read($this->vehicle, ['value' => 47600])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.value.0', "This implies 667 km a day — over 3x this vehicle's average of 48 km a day. Double-check the reading before saving.")
        ->assertJsonPath('error.details.fields.confirm_warning.0', fn ($m) => is_string($m));

    read($this->vehicle, ['value' => 47600, 'confirm_warning' => true])->assertCreated();
});

it('accepts a plausible reading and re-derives the odometer and rate', function () {
    read($this->vehicle, ['value' => 45750])->assertCreated()->assertJsonPath('data.value', '45750.000');

    $this->getJson("/api/v1/vehicles/{$this->vehicle}")
        ->assertJsonPath('data.odometer.value', 45750)
        ->assertJsonPath('data.odometer.read_on', '2026-10-11')
        // (45,750 − 44,160) / 33 days since the seed's baseline reading.
        ->assertJsonPath('data.odometer.avg_daily_km', 1590 / 33);
});

it('refuses future and back-dated readings', function () {
    read($this->vehicle, ['value' => 45750, 'read_on' => '2026-10-12'])->assertStatus(422)->assertJsonPath('error.details.fields.read_on.0', 'A reading cannot be dated in the future.');
    read($this->vehicle, ['value' => 45750, 'read_on' => '2026-10-01'])->assertStatus(422);
});

it('voids a wrong reading by appending a correction, never by editing', function () {
    $wrong = read($this->vehicle, ['value' => 49999, 'confirm_warning' => true])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $this->postJson("/api/v1/vehicles/{$this->vehicle}/readings/{$wrong}/void", ['reason' => 'Typo at the counter'])
        ->assertCreated()
        ->assertJsonPath('data.voids_reading_id', $wrong)
        ->assertJsonPath('data.value', null);

    $this->getJson("/api/v1/vehicles/{$this->vehicle}")->assertJsonPath('data.odometer.value', 45600);
    $this->getJson("/api/v1/vehicles/{$this->vehicle}/readings")->assertJsonPath('meta.total', 4);
    $this->postJson("/api/v1/vehicles/{$this->vehicle}/readings/{$wrong}/void", ['reason' => 'again'])->assertStatus(409);
});

it('keeps readings to vehicle:update holders in scope', function () {
    Sanctum::actingAs($this->world->user('viewer@mekanikomore.ph'));
    read($this->vehicle, ['value' => 45700])->assertForbidden();

    Sanctum::actingAs($this->world->user('fleet@northwind.ph'));
    read($this->vehicle, ['value' => 45700])->assertNotFound();
});
