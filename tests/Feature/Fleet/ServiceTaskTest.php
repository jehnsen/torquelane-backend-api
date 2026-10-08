<?php

declare(strict_types=1);

use App\Models\MaintenanceState;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->oil = $this->world->id('task:oil-filter');
});

it('lists the catalogue in order to everyone beneath the organization', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $tasks = $this->getJson('/api/v1/service-tasks')->assertOk()->json('data');
    expect(count($tasks))->toBe(12)->and($tasks[0]['code'])->toBe('oil-filter')->and($tasks[0]['estimated_cost_cents'])->toBe(320000);
});

it('never touches recorded history when an interval changes; due dates move', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $vehicle = $this->world->id('veh-001');
    $before = asSystem(fn () => MaintenanceState::query()->orderBy('id')->get(['last_done_value', 'last_done_on'])->toArray());
    $due = fn (): string => collect($this->getJson("/api/v1/vehicles/{$vehicle}/health")->json('data.items'))->firstWhere('service_task_id', $this->oil)['due_date'];

    expect($due())->toBe('2026-12-31');
    $this->patchJson("/api/v1/service-tasks/{$this->oil}", ['interval_km' => 10000])->assertOk();

    expect(asSystem(fn () => MaintenanceState::query()->orderBy('id')->get(['last_done_value', 'last_done_on'])->toArray()))->toBe($before)
        ->and($due())->toBe('2027-03-18');
});

it('refuses to delete a task with history, and keeps writes to staff with settings:manage', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $this->deleteJson("/api/v1/service-tasks/{$this->oil}")->assertStatus(409);
    $new = $this->postJson('/api/v1/service-tasks', ['code' => 'aircon-clean', 'name' => 'Aircon cleaning', 'category' => 'body', 'interval_km' => 20000, 'interval_months' => 12])
        ->assertCreated()->assertJsonPath('data.position', 12)->json('data.id');
    $this->postJson('/api/v1/service-tasks', ['code' => 'aircon-clean', 'name' => 'x', 'category' => 'body', 'interval_km' => 1, 'interval_months' => 1])->assertStatus(422);
    $this->deleteJson("/api/v1/service-tasks/{$new}")->assertNoContent();

    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $this->patchJson("/api/v1/service-tasks/{$this->oil}", ['interval_km' => 1])->assertForbidden();
});
