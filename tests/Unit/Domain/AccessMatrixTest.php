<?php

declare(strict_types=1);

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Capability;
use App\Domain\Access\Role;
use App\Domain\Access\Side;

it('puts the new roles on the staff side', function () {
    expect(Role::BranchManager->side())->toBe(Side::Staff)
        ->and(Role::Cashier->side())->toBe(Side::Staff);
});

it('gives the branch manager everything but organization:manage', function () {
    $missing = array_values(array_filter(Capability::cases(), fn (Capability $c): bool => ! AccessMatrix::can(Role::BranchManager, $c)));

    expect($missing)->toBe([Capability::OrganizationManage]);
});

it('gives the cashier only customer:manage until the POS module arrives', function () {
    expect(AccessMatrix::capabilitiesOf(Role::Cashier))->toBe([Capability::CustomerManage]);
});

it('keeps access:manage with staff only', function () {
    foreach (Role::portal() as $role) {
        expect(AccessMatrix::can($role, Capability::AccessManage))->toBeFalse($role->value);
    }
});

it('lets a granter hand out only roles whose grants they hold', function (Role $granter, Role $target, bool $allowed) {
    expect(AccessMatrix::canGrant($granter, $target))->toBe($allowed);
})->with([
    'admin → admin' => [Role::ProviderAdmin, Role::ProviderAdmin, true],
    'admin → branch manager' => [Role::ProviderAdmin, Role::BranchManager, true],
    'branch manager → admin (escalation)' => [Role::BranchManager, Role::ProviderAdmin, false],
    'branch manager → branch manager' => [Role::BranchManager, Role::BranchManager, true],
    'branch manager → advisor' => [Role::BranchManager, Role::ServiceAdvisor, true],
    'branch manager → fleet manager' => [Role::BranchManager, Role::FleetManager, true],
    'advisor → anyone (no access:manage)' => [Role::ServiceAdvisor, Role::Viewer, false],
    'fleet manager → viewer (no access:manage)' => [Role::FleetManager, Role::Viewer, false],
]);

it('reports capabilities in declaration order, so /me is stable', function () {
    $values = array_map(fn (Capability $c): string => $c->value, AccessMatrix::capabilitiesOf(Role::ProviderAdmin));

    expect($values)->toBe(array_map(fn (Capability $c): string => $c->value, Capability::cases()));
});
