<?php

declare(strict_types=1);

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Capability;
use App\Domain\Access\Role;
use Tests\Golden\Support\WebFixtures;

/*
 * Replays ../web/fixtures/golden/rbac.json (lib/rbac.ts at
 * pms-monitoring-frontend@d45871e): every role × capability `can()` and
 * `denialReason()`, and the exported constants. Every ported grant is
 * identical; the API's additions are listed here and nowhere else.
 */

/** Grants the API adds to ported roles (capabilities that do not exist in ../web). */
const API_GRANTS_ON_PORTED_ROLES = [
    'provider_admin' => ['customer:manage', 'organization:manage', 'inventory:view', 'inventory:manage'],
    'service_advisor' => ['customer:manage', 'inventory:view'],
    'provider_technician' => ['inventory:view'],
    'fleet_manager' => ['customer:manage'],
];

dataset('rbac cases', function (): array {
    $cases = [];
    foreach (WebFixtures::cases('rbac') as $case) {
        if (in_array($case['fn'], ['can', 'denialReason'], true)) {
            $cases[$case['case']] = [$case];
        }
    }

    return $cases;
});

it('reproduces can() and denialReason() for every role and capability', function (array $case) {
    [$role, $capability] = $case['input'];
    $role = is_string($role) ? Role::from($role) : null;
    $capability = Capability::from($capability);

    $actual = $case['fn'] === 'can'
        ? AccessMatrix::can($role, $capability)
        : AccessMatrix::denialReason($role, $capability);

    expect($actual)->toBe($case['output']);
})->with('rbac cases');

function rbacConstants(): array
{
    foreach (WebFixtures::cases('rbac') as $case) {
        if ($case['fn'] === '$constants') {
            return $case['output'];
        }
    }

    throw new LogicException('rbac.json has no $constants case.');
}

it('keeps every ported grant identical, plus only the documented API additions', function () {
    foreach (rbacConstants()['ROLE_CAPABILITIES'] as $role => $grants) {
        $ours = array_map(fn (Capability $c): string => $c->value, AccessMatrix::capabilitiesOf(Role::from($role)));
        $expected = array_merge($grants, API_GRANTS_ON_PORTED_ROLES[$role] ?? []);
        sort($ours);
        sort($expected);

        expect($ours)->toBe($expected, "grants for {$role}");
    }
});

it('keeps the ported roles, labels and descriptions verbatim', function () {
    $constants = rbacConstants();

    foreach ($constants['ROLE_LABEL'] as $role => $label) {
        expect(Role::from($role)->label())->toBe($label)
            ->and(Role::from($role)->description())->toBe($constants['ROLE_DESCRIPTION'][$role]);
    }
    foreach ($constants['CAPABILITY_LABEL'] as $capability => $label) {
        expect(Capability::from($capability)->label())->toBe($label);
    }

    $portal = array_map(fn (Role $r): string => $r->value, Role::portal());
    $staff = array_map(fn (Role $r): string => $r->value, array_filter(Role::staff(), fn (Role $r): bool => ! $r->isApiOnly()));
    sort($portal);
    sort($staff);
    $clientRoles = $constants['CLIENT_ROLES'];
    $providerRoles = $constants['PROVIDER_ROLES'];
    sort($clientRoles);
    sort($providerRoles);

    expect($portal)->toBe($clientRoles)->and($staff)->toBe($providerRoles);
});

it('adds only the documented capabilities and roles', function () {
    $constants = rbacConstants();

    $added = array_values(array_diff(array_map(fn (Capability $c): string => $c->value, Capability::cases()), $constants['ALL_CAPABILITIES']));
    expect($added)->toBe(['customer:manage', 'organization:manage', 'inventory:view', 'inventory:manage'])
        ->and(array_values(array_map(fn (Capability $c): string => $c->value, array_filter(Capability::cases(), fn (Capability $c): bool => $c->isApiOnly()))))->toBe($added);

    $newRoles = array_values(array_diff(array_map(fn (Role $r): string => $r->value, Role::cases()), array_keys($constants['ROLE_LABEL'])));
    expect($newRoles)->toBe(['branch_manager', 'cashier']);
});
