<?php

declare(strict_types=1);

use Tests\Golden\Support\TenancyPort;
use Tests\Golden\Support\WebFixtures;

/*
 * Replays ../web/fixtures/golden/tenancy.json (the frontend's lib/tenancy.ts,
 * captured at pms-monitoring-frontend@d45871e) against the PHP ports:
 * TenantScopeResolver, Branding, ApprovalBands, TenantScope::key().
 *
 * Every case must match the fixture exactly, except the documented
 * divergences below. Each divergence is listed by case name with the API's
 * output, and the test fails if a listed case stops diverging (stale list)
 * or an unlisted one starts (unintended change).
 */

/**
 * Deliberate differences from the frontend, by fixture case → the API's output.
 *
 * WHY: staff roles must never be pinned to a customer account in the API
 * (Phase 1 brief: "staff roles must not be [pinned]"; the invite rules and
 * the users_portal_account_check CHECK enforce it). The frontend resolved a
 * provider-side role pinned to a client to that client's scope; the API
 * treats such a session as corrupt and resolves no scope
 * (role_side_mismatch). Three fixture cases exercise it. (For the
 * suspended client the resolved scope is null either way; only the reason
 * differs: the API rejects the pinning before it looks at the account.)
 *
 * The suspended-account RULE CHANGE (staff keep read access, portal users are
 * denied, nobody starts new work) alters NO fixture output: the frontend's
 * visibleFleetClientIds already kept suspended clients in a provider-side
 * scope, and its client-side denial (client_suspended) is the API's
 * account_suspended. The new half of the rule (no new work for a suspended
 * account) has no frontend function, so no fixture; it is covered by
 * tests/Feature/Tenancy/SuspendedAccountTest.
 */
const TENANCY_DIVERGENCES = [
    'sweep › explainTenantScope › provider-side role pinned to one client' => ['scope' => null, 'denial' => 'role_side_mismatch'],
    'sweep › resolveTenantScope › provider-side role pinned to one client' => null,
    'sweep › explainTenantScope › provider-side role pinned to the suspended client' => ['scope' => null, 'denial' => 'role_side_mismatch'],
];

dataset('tenancy cases', function (): array {
    $cases = [];
    foreach (WebFixtures::cases('tenancy') as $i => $case) {
        if (in_array($case['fn'], TenancyPort::REPLAYED, true)) {
            $cases[sprintf('#%03d %s', $i, $case['case'])] = [$case];
        }
    }

    return $cases;
});

it('reproduces the frontend\'s tenancy rules', function (array $case) {
    $actual = TenancyPort::call($case['fn'], $case['input']);

    if (array_key_exists($case['case'], TENANCY_DIVERGENCES)) {
        expect($actual)->toEqual(TENANCY_DIVERGENCES[$case['case']])
            ->and($actual)->not->toEqual($case['output'], 'Listed as a divergence but now matches the fixture: remove it from TENANCY_DIVERGENCES.');

        return;
    }

    expect($actual)->toEqual($case['output']);
})->with('tenancy cases');

it('replays or explicitly defers every function in the fixture', function () {
    $functions = array_values(array_unique(array_filter(
        array_column(WebFixtures::cases('tenancy'), 'fn'),
        fn (string $fn): bool => ! str_starts_with($fn, '$'),
    )));
    sort($functions);

    $accounted = array_merge(TenancyPort::REPLAYED, array_keys(TenancyPort::DEFERRED));
    sort($accounted);

    expect($functions)->toBe($accounted);
});

it('names only divergences that exist in the fixture', function () {
    $names = array_column(WebFixtures::cases('tenancy'), 'case');

    foreach (array_keys(TENANCY_DIVERGENCES) as $name) {
        expect($names)->toContain($name);
    }
});
