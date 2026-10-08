<?php

declare(strict_types=1);

use App\Domain\Access\Side;
use App\Domain\Tenancy\AccountFacts;
use App\Domain\Tenancy\AccountStanding;
use App\Domain\Tenancy\OrganizationFacts;
use App\Domain\Tenancy\ScopeDenial;
use App\Domain\Tenancy\SessionFacts;
use App\Domain\Tenancy\TenantResolution;
use App\Domain\Tenancy\TenantScope;
use App\Domain\Tenancy\TenantScopeResolver;

/*
 * The golden replay pins the ported behaviour; these pin the API's own
 * additions and the deliberate suspended-account rule change.
 */

function staffSession(string $role = 'provider_admin', ?string $account = null, bool $disabled = false): SessionFacts
{
    return new SessionFacts('u1', 'org', Side::Staff, $role, $account, $disabled);
}

function portalSession(string $account = 'acme', string $role = 'fleet_manager'): SessionFacts
{
    return new SessionFacts('u2', 'org', Side::Portal, $role, $account);
}

$organizations = [new OrganizationFacts('org')];
$accounts = [new AccountFacts('acme', 'org'), new AccountFacts('bust', 'org', suspended: true), new AccountFacts('foreign', 'other')];

it('denies a portal user of a suspended account with account_suspended', function () use ($organizations, $accounts) {
    expect(TenantScopeResolver::explain(portalSession('bust'), $organizations, $accounts)->denial)->toBe(ScopeDenial::AccountSuspended);
});

it('keeps a suspended account in staff scope so staff can still read it', function () use ($organizations, $accounts) {
    $scope = TenantScopeResolver::explain(staffSession(), $organizations, $accounts)->scope;

    expect(TenantScopeResolver::visibleAccountIds($scope, $accounts))->toBe(['acme', 'bust']);
});

it('stops new work for a suspended account but keeps it readable', function () {
    expect(AccountStanding::acceptsNewWork(AccountStanding::SUSPENDED))->toBeFalse()
        ->and(AccountStanding::staffMayRead(AccountStanding::SUSPENDED))->toBeTrue()
        ->and(AccountStanding::portalMayAccess(AccountStanding::SUSPENDED))->toBeFalse()
        ->and(AccountStanding::acceptsNewWork(AccountStanding::ACTIVE))->toBeTrue();
});

it('never lets a portal user see a sibling account', function () use ($organizations, $accounts) {
    $scope = TenantScopeResolver::explain(portalSession('acme'), $organizations, $accounts)->scope;

    expect(TenantScopeResolver::visibleAccountIds($scope, $accounts))->toBe(['acme']);
});

it('fails closed with a reason for every ambiguous session', function (?SessionFacts $session, array $orgs, ScopeDenial $denial) use ($accounts) {
    expect(TenantScopeResolver::explain($session, $orgs, $accounts)->denial)->toBe($denial);
})->with([
    'no session' => [null, [new OrganizationFacts('org')], ScopeDenial::NoSession],
    'disabled user' => [staffSession(disabled: true), [new OrganizationFacts('org')], ScopeDenial::UserDisabled],
    'unknown organization' => [staffSession(), [], ScopeDenial::UnknownOrganization],
    'suspended organization' => [staffSession(), [new OrganizationFacts('org', suspended: true)], ScopeDenial::OrganizationSuspended],
    'unrecognised role' => [staffSession('owner'), [new OrganizationFacts('org')], ScopeDenial::UnknownRole],
    'staff role on the portal side' => [new SessionFacts('u', 'org', Side::Portal, 'provider_admin', 'acme'), [new OrganizationFacts('org')], ScopeDenial::RoleSideMismatch],
    'portal role on the staff side' => [new SessionFacts('u', 'org', Side::Staff, 'viewer', null), [new OrganizationFacts('org')], ScopeDenial::RoleSideMismatch],
    'staff pinned to an account' => [staffSession(account: 'acme'), [new OrganizationFacts('org')], ScopeDenial::RoleSideMismatch],
    'portal user without an account' => [new SessionFacts('u', 'org', Side::Portal, 'viewer', null), [new OrganizationFacts('org')], ScopeDenial::RoleSideMismatch],
    'account from another organization' => [portalSession('foreign'), [new OrganizationFacts('org')], ScopeDenial::OrganizationMismatch],
    'unknown account' => [portalSession('ghost'), [new OrganizationFacts('org')], ScopeDenial::UnknownAccount],
]);

it('treats the new staff roles as staff', function () use ($organizations, $accounts) {
    foreach (['branch_manager', 'cashier'] as $role) {
        expect(TenantScopeResolver::explain(staffSession($role), $organizations, $accounts)->scope)->toEqual(TenantScope::staff('org'));
    }
});

it('keys organization-wide and account scopes apart', function () {
    expect(TenantScope::staff('org')->key())->toBe('organization:org')
        ->and(TenantScope::portal('org', 'acme')->key())->toBe('account:acme');
});

it('builds a full context with branches for staff and none for portal users', function () use ($organizations, $accounts) {
    $staff = TenantResolution::build(staffSession('branch_manager'), $organizations, $accounts, ['b1', 'b2'], ['b2'], null);
    $portal = TenantResolution::build(portalSession(), $organizations, $accounts, ['b1', 'b2'], [], 'b1');

    expect($staff->context?->allowedBranchIds)->toBe(['b2'])
        ->and($staff->context?->selectedBranchId)->toBe('b2')
        ->and($staff->context?->branchRestricted)->toBeTrue()
        ->and($portal->context?->allowedBranchIds)->toBe([])
        ->and($portal->context?->selectedBranchId)->toBeNull();
});
