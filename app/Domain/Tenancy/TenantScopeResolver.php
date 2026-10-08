<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Access\Role;
use App\Domain\Access\Side;

/**
 * Port of ../web/lib/tenancy.ts: `explainTenantScope`, `resolveTenantScope`,
 * `visibleFleetClientIds` and `scopeAccounts`.
 *
 * Fails closed, always. No session, or an ambiguous one, resolves to no scope,
 * never to a wider one. The shape is organization → customer accounts; staff
 * see every account beneath their organization, a portal user exactly one,
 * never a sibling under the same organization (the leak a bare
 * organization_id filter would let through).
 *
 * Deliberate differences from the frontend, each pinned by the golden test:
 *  - A staff role pinned to a customer account is `role_side_mismatch`. The
 *    frontend gave it that one client's scope; here staff roles must never be
 *    pinned (the invite rules and a database CHECK forbid it), so a row that
 *    somehow is pinned is corrupt and resolves to nothing.
 *  - A suspended organization, a disabled user and an unrecognised role each
 *    deny with their own reason (the frontend had no such states).
 *  - Suspension: a portal user of a suspended account is still denied
 *    (`account_suspended`), but staff scope keeps suspended accounts visible,
 *    exactly as `visibleFleetClientIds` always did. What staff lose is the
 *    right to start new work for one: see AccountStanding.
 */
final class TenantScopeResolver
{
    /**
     * @param  list<OrganizationFacts>  $organizations  candidates; the session's own is looked up by id
     * @param  list<AccountFacts>  $accounts  candidates; the session's own is looked up by id
     */
    public static function explain(?SessionFacts $session, array $organizations, array $accounts): ScopeResolution
    {
        if ($session === null) {
            return ScopeResolution::denied(ScopeDenial::NoSession);
        }
        if ($session->disabled) {
            return ScopeResolution::denied(ScopeDenial::UserDisabled);
        }
        if ($session->organizationId === null || $session->organizationId === '') {
            return ScopeResolution::denied(ScopeDenial::NoOrganization);
        }

        $organization = self::find($organizations, $session->organizationId);
        if ($organization === null) {
            return ScopeResolution::denied(ScopeDenial::UnknownOrganization);
        }
        if ($organization->suspended) {
            return ScopeResolution::denied(ScopeDenial::OrganizationSuspended);
        }

        $role = $session->role === null ? null : Role::tryFrom($session->role);
        if ($role === null) {
            return ScopeResolution::denied(ScopeDenial::UnknownRole);
        }
        // A portal role with no account would otherwise inherit organization-wide
        // visibility: precisely the escalation this module exists to prevent.
        if ($role->side() !== $session->side) {
            return ScopeResolution::denied(ScopeDenial::RoleSideMismatch);
        }

        if ($session->side === Side::Staff) {
            return $session->customerAccountId === null
                ? ScopeResolution::granted(TenantScope::staff($organization->id))
                : ScopeResolution::denied(ScopeDenial::RoleSideMismatch);
        }

        if ($session->customerAccountId === null) {
            return ScopeResolution::denied(ScopeDenial::RoleSideMismatch);
        }

        $account = self::find($accounts, $session->customerAccountId);
        if ($account === null) {
            return ScopeResolution::denied(ScopeDenial::UnknownAccount);
        }
        if ($account->organizationId !== $organization->id) {
            return ScopeResolution::denied(ScopeDenial::OrganizationMismatch);
        }
        if ($account->suspended) {
            return ScopeResolution::denied(ScopeDenial::AccountSuspended);
        }

        return ScopeResolution::granted(TenantScope::portal($organization->id, $account->id));
    }

    /**
     * Every customer account id the scope may read; empty without a scope.
     * Staff see suspended accounts too (collections, history).
     *
     * @param  list<AccountFacts>  $accounts
     * @return list<string>
     */
    public static function visibleAccountIds(?TenantScope $scope, array $accounts): array
    {
        if ($scope === null) {
            return [];
        }
        if ($scope->customerAccountId !== null) {
            return [$scope->customerAccountId];
        }

        return array_values(array_map(
            fn (AccountFacts $account): string => $account->id,
            array_filter($accounts, fn (AccountFacts $account): bool => $account->organizationId === $scope->organizationId),
        ));
    }

    /**
     * People a scope may enumerate. A portal user sees only their own
     * account's people: not the organization's staff, not a sibling's.
     *
     * @template T of array<string, mixed>
     *
     * @param  list<T>  $entries  each with `organization_id` and `customer_account_id`
     * @return list<T>
     */
    public static function directory(?TenantScope $scope, array $entries): array
    {
        if ($scope === null) {
            return [];
        }

        return array_values(array_filter($entries, function (array $entry) use ($scope): bool {
            if (($entry['organization_id'] ?? null) !== $scope->organizationId) {
                return false;
            }

            return $scope->customerAccountId === null
                || ($entry['customer_account_id'] ?? null) === $scope->customerAccountId;
        }));
    }

    /**
     * @template T of OrganizationFacts|AccountFacts
     *
     * @param  list<T>  $candidates
     * @return T|null
     */
    private static function find(array $candidates, string $id): OrganizationFacts|AccountFacts|null
    {
        foreach ($candidates as $candidate) {
            if ($candidate->id === $id) {
                return $candidate;
            }
        }

        return null;
    }
}
