<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Alerts are derived on every read and never stored; read/dismiss state is
 * the caller's own, bucketed per scope (port of AlertInteractionByScope):
 * a staff dismissal decides nothing for a customer, and vice versa.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

it('derives alerts with deterministic ids and counts', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $response = $this->getJson('/api/v1/alerts')->assertOk();
    $ids = array_column($response->json('data'), 'id');

    expect($ids)->not->toBeEmpty();
    foreach ($ids as $id) {
        expect($id)->toMatch('/^(pms|doc|licence):[0-9a-z]{26}(:[0-9a-z]{26})?$/');
    }
    expect($response->json('meta'))->toBe(['total' => count($ids), 'unread_count' => count($ids), 'dismissed_count' => 0]);
});

it('marks read, dismisses and restores, in the caller\'s own bucket', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    [$first, $second] = array_column($this->getJson('/api/v1/alerts')->json('data'), 'id');

    $this->postJson('/api/v1/alerts/read', ['alert_ids' => [$first]])->assertNoContent();
    $this->postJson('/api/v1/alerts/dismiss', ['alert_ids' => [$second]])->assertNoContent();

    $view = $this->getJson('/api/v1/alerts')->json();
    expect(array_column($view['data'], 'id'))->not->toContain($second)
        ->and(collect($view['data'])->firstWhere('id', $first)['read'])->toBeTrue()
        ->and($view['meta']['dismissed_count'])->toBe(1);
    expect(collect($this->getJson('/api/v1/alerts?include_dismissed=1')->json('data'))->firstWhere('id', $second)['dismissed'])->toBeTrue();

    // Another user, and a staff member seeing the same alert, are unaffected.
    Sanctum::actingAs($this->world->user('ops@mekanikomore.ph'));
    expect(array_column($this->getJson('/api/v1/alerts')->json('data'), 'id'))->toContain($second);
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    expect(array_column($this->getJson('/api/v1/alerts')->json('data'), 'id'))->toContain($second);

    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $this->postJson('/api/v1/alerts/restore', ['alert_ids' => [$second]])->assertNoContent();
    $restored = collect($this->getJson('/api/v1/alerts')->json('data'))->firstWhere('id', $second);
    // Dismissing is not reading: it comes back unread.
    expect($restored['read'])->toBeFalse();
});

it('refuses malformed alert ids', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->postJson('/api/v1/alerts/dismiss', ['alert_ids' => ['not an id']])->assertStatus(422);
});

it('never shows another organization\'s alerts', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $ids = implode(' ', array_column($this->getJson('/api/v1/alerts')->json('data'), 'id'));

    expect($ids)->not->toContain($this->world->id('rival:vehicle'))
        ->and($ids)->not->toContain($this->world->id('rival:document'));
});
