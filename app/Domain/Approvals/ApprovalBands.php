<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Tenancy\TenantScope;

/**
 * Per-account approval bands. Port of `approvalSettingsForClient` and
 * `effectiveApprovalSettings`.
 *
 * `customer_accounts.approval_threshold_overrides` is SPARSE: a key it names
 * wins, a key it omits inherits the organization's default. NULL means
 * "inherit everything" and is stored distinctly from `{}`, though both
 * resolve the same. An explicit 0 is a real value, never "unset".
 * Organization-wide (staff) views use the defaults outright: accounts can and
 * do disagree, so there is no single correct band to show across them.
 */
final class ApprovalBands
{
    /**
     * The keys an override may name (snake_case, money in centavos). Phase 1
     * stores and validates them; the approval phase supplies the defaults.
     */
    public const array OVERRIDE_KEYS = [
        'auto_approve_under_cents',
        'ops_approval_under_cents',
        'sla_hours',
        'variance_threshold_pct',
        'default_parts_source',
        'monthly_budget_cents',
    ];

    /**
     * @param  array<string, mixed>|null  $overrides
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function forAccount(?array $overrides, array $defaults): array
    {
        return $overrides === null ? $defaults : array_merge($defaults, $overrides);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, array<string, mixed>|null>  $overridesByAccountId  every account the caller can see
     * @return array<string, mixed>
     */
    public static function forScope(array $defaults, ?TenantScope $scope, array $overridesByAccountId): array
    {
        if ($scope === null || $scope->customerAccountId === null) {
            return $defaults;
        }
        if (! array_key_exists($scope->customerAccountId, $overridesByAccountId)) {
            return $defaults;
        }

        return self::forAccount($overridesByAccountId[$scope->customerAccountId], $defaults);
    }
}
