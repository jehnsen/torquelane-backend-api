<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

beforeEach(function () {
    $this->world = World::build();
});

function me(?string $branch): TestResponse
{
    return test()->getJson('/api/v1/me', $branch === null ? [] : ['X-Branch-Id' => $branch]);
}

it('selects an allowed branch named in X-Branch-Id', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $repair = $this->world->id('mekanikomor-binan');

    me($repair)->assertOk()
        ->assertJsonPath('data.branches.selected', $repair)
        ->assertJsonPath('data.modules.active', ['repair_pms']);
});

it('accepts "all" for multi-branch staff', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    me('all')->assertOk()->assertJsonPath('data.branches.selected', 'all');
});

it('refuses a branch the caller cannot work in, with the reason', function (string $who, string $branchKey) {
    Sanctum::actingAs($this->world->user($who));

    me($this->world->id($branchKey))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.details.reason', 'branch_not_allowed');
})->with([
    'another organization\'s branch' => ['owner@mekanikomore.ph', 'rival:branch'],
    'a branch outside the pins' => ['manager.samahuzai@mekanikomore.ph', 'mekanikomor-binan'],
]);

it('refuses a malformed header rather than ignoring it', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    me('not-a-branch')->assertForbidden()->assertJsonPath('error.details.reason', 'branch_not_allowed');
});

it('narrows branch-owned lists to the selected branch', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $repair = $this->getJson('/api/v1/bays', ['X-Branch-Id' => $this->world->id('mekanikomor-binan')])->assertOk();
    $all = $this->getJson('/api/v1/bays')->assertOk();

    expect($repair->json('meta.total'))->toBe(5)
        ->and($all->json('meta.total'))->toBe(7);
});

it('ignores the header for portal users, who have no branches', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    me($this->world->id('rival:branch'))->assertOk()->assertJsonPath('data.branches.selected', null);
});
