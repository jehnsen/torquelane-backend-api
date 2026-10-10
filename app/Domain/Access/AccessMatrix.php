<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * The single role → capability matrix (ported from ../web/lib/rbac.ts
 * `ROLE_CAPABILITIES`). The frontend stops carrying its own copy and reads the
 * resolved list from GET /me.
 *
 * Every ported grant is identical to the frontend's (golden-tested against
 * rbac.json). What the API adds:
 *  - `customer:manage` for provider_admin, service_advisor and fleet_manager
 *    (the fleet manager only ever within their own account: scope, not this
 *    table, keeps it there);
 *  - `organization:manage` for provider_admin only;
 *  - `inventory:view` for every staff role and `inventory:manage` for
 *    provider_admin and branch_manager (the shop's own stock room; never a
 *    portal role);
 *  - the two new staff roles, branch_manager and cashier;
 *  - billing (Phase 7): `billing:view` for every staff role but the
 *    technician, and for the portal's fleet manager, purchasing officer and
 *    viewer (their own account's invoices only, by scope); `billing:manage`
 *    for provider_admin, branch_manager, service_advisor and cashier;
 *    `billing:void` for provider_admin and branch_manager;
 *  - the books (Phase 8): `ledger:view` for provider_admin and branch_manager
 *    (within their branches), `ledger:manage` for provider_admin.
 */
final class AccessMatrix
{
    private const array GRANTS = [
        'viewer' => ['billing:view'],
        'technician' => [
            'vehicle:update',
            'workorder:update',
            'workorder:complete',
            'document:upload',
        ],
        'purchasing_officer' => ['workorder:approve', 'po:issue', 'document:upload', 'billing:view'],
        'operations' => [
            'vehicle:update',
            'vehicle:manage',
            'workorder:create',
            'workorder:update',
            'workorder:complete',
            'workorder:approve',
            'document:upload',
        ],
        // `access:manage` is deliberately absent: user accounts are managed by
        // staff, even for a customer's own fleet.
        'fleet_manager' => [
            'vehicle:update',
            'vehicle:manage',
            'workorder:create',
            'workorder:update',
            'workorder:complete',
            'workorder:approve',
            'po:issue',
            'document:upload',
            'document:delete',
            'settings:manage',
            'customer:manage',
            'billing:view',
        ],

        // ------------------------------------------------------------ staff
        'provider_technician' => [
            'vehicle:update',
            'workorder:update',
            'workorder:complete',
            'document:upload',
            'inventory:view',
        ],
        'service_advisor' => [
            'vehicle:update',
            'vehicle:manage',
            'workorder:create',
            'workorder:update',
            'document:upload',
            'customer:manage',
            'inventory:view',
            'billing:view',
            'billing:manage',
        ],
        'cashier' => ['customer:manage', 'inventory:view', 'billing:view', 'billing:manage'],
        // Everything but organization:manage, applied only to the branches the
        // manager is pinned to (branch_user).
        'branch_manager' => [
            'vehicle:update',
            'vehicle:manage',
            'workorder:create',
            'workorder:update',
            'workorder:complete',
            'workorder:approve',
            'po:issue',
            'document:upload',
            'document:delete',
            'settings:manage',
            'access:manage',
            'customer:manage',
            'inventory:view',
            'inventory:manage',
            'billing:view',
            'billing:manage',
            'billing:void',
            'ledger:view',
        ],
        'provider_admin' => [
            'vehicle:update',
            'vehicle:manage',
            'workorder:create',
            'workorder:update',
            'workorder:complete',
            'workorder:approve',
            'po:issue',
            'document:upload',
            'document:delete',
            'settings:manage',
            'access:manage',
            'customer:manage',
            'organization:manage',
            'inventory:view',
            'inventory:manage',
            'billing:view',
            'billing:manage',
            'billing:void',
            'ledger:view',
            'ledger:manage',
        ],
    ];

    public static function can(?Role $role, Capability $capability): bool
    {
        return $role !== null && in_array($capability, self::capabilitiesOf($role), true);
    }

    /**
     * In Capability declaration order, so /me is stable.
     *
     * @return list<Capability>
     */
    public static function capabilitiesOf(Role $role): array
    {
        $granted = self::GRANTS[$role->value];

        return array_values(array_filter(
            Capability::cases(),
            fn (Capability $capability): bool => in_array($capability->value, $granted, true),
        ));
    }

    /** Why an action is unavailable. Verbatim port of the frontend's `denialReason`. */
    public static function denialReason(?Role $role, Capability $capability): string
    {
        if ($role === null) {
            return 'Sign in to do this.';
        }

        return sprintf(
            "%s doesn't have permission to %s.",
            $role->label(),
            mb_strtolower($capability->label()),
        );
    }

    /**
     * Whether $granter may hand $target to someone (invite or role change).
     * No escalation: the target role's grants must be a subset of the
     * granter's own, and the granter must hold access:manage.
     */
    public static function canGrant(Role $granter, Role $target): bool
    {
        if (! self::can($granter, Capability::AccessManage)) {
            return false;
        }

        return array_diff(
            array_map(fn (Capability $c): string => $c->value, self::capabilitiesOf($target)),
            array_map(fn (Capability $c): string => $c->value, self::capabilitiesOf($granter)),
        ) === [];
    }
}
