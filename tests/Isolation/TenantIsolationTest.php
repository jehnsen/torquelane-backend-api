<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Tenancy\TenantManager;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Isolation\TenantIsolationSuite;
use Tests\Support\World;

/*
 * Every demo user × every isolation-tested GET route, with every route
 * parameter filled from every ownership bucket (TenantIsolationSuite). The
 * dataset is the seeder's own user list plus the second organization's admin
 * and portal user, so a new demo account or a new route is covered without
 * touching this file. This suite grows every phase.
 */

beforeEach(function () {
    $this->world = World::build();
    $this->suite = new TenantIsolationSuite($this->world);
});

it('never shows a caller another tenant\'s records', function (string $who) {
    $user = $this->world->user($who);
    $allowedBranches = app(TenantManager::class)->system('isolation test', function () use ($user): ?array {
        $pins = $user->branches()->get(['branches.id'])->map(fn (Branch $branch): string => $branch->id)->all();

        return $user->customer_account_id === null && $pins !== [] ? array_values($pins) : null;
    });

    // The probe count per user exceeds the api rate limit; throttling is not what this suite tests.
    $this->withoutMiddleware(ThrottleRequests::class);
    Sanctum::actingAs($user);

    $probes = $this->suite->probes();
    expect($probes)->not->toBeEmpty();

    foreach ($probes as $probe) {
        $this->suite->assertProbe($user, $allowedBranches, $probe, $this->getJson($probe['uri']));
    }
})->with(fn (): array => array_merge(World::demoEmails(), ['rival:admin', 'rival:portal']));

it('answers 401 to every isolation-tested route without a session', function () {
    foreach ($this->suite->probes() as $probe) {
        $this->getJson($probe['uri'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }
});

it('generates probes for every isolation-tested route', function () {
    /** @var array<string, array<string, string>> $coverage */
    $coverage = require __DIR__.'/coverage.php';
    $declared = array_keys(array_filter($coverage, fn (array $entry): bool => isset($entry['isolation'])));
    sort($declared);

    $probed = array_values(array_unique(array_column($this->suite->probes(), 'route')));
    sort($probed);

    expect($probed)->toBe($declared);
});

it('probes each parameterised route with records from another organization and a sibling account', function () {
    $params = array_merge(...array_column($this->suite->probes(), 'params'));

    expect($params)
        ->toContain($this->world->id('rival:account'))
        ->toContain($this->world->id('rival:branch'))
        ->toContain($this->world->id('rival:bay'))
        ->toContain($this->world->id('rival:technician'))
        ->toContain($this->world->id('fc-northwind'))
        ->toContain($this->world->id('walk-in'));
});
