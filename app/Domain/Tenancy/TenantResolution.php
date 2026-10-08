<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Access\Role;
use LogicException;

/**
 * The full tenant context for one request, or the reason there is none.
 */
final readonly class TenantResolution
{
    private function __construct(
        public ?TenantContext $context,
        public ?ScopeDenial $denial,
    ) {}

    /**
     * Scope (TenantScopeResolver) first, then branch selection for staff.
     *
     * @param  list<OrganizationFacts>  $organizations
     * @param  list<AccountFacts>  $accounts
     * @param  list<string>  $organizationBranchIds
     * @param  list<string>  $pinnedBranchIds
     */
    public static function build(
        SessionFacts $session,
        array $organizations,
        array $accounts,
        array $organizationBranchIds,
        array $pinnedBranchIds,
        ?string $branchHeader,
    ): self {
        $resolution = TenantScopeResolver::explain($session, $organizations, $accounts);
        if ($resolution->scope === null) {
            return new self(null, $resolution->denial ?? throw new LogicException('A resolution has a scope or a denial.'));
        }

        $role = Role::from((string) $session->role);
        $branches = $resolution->scope->isStaff()
            ? BranchSelection::resolve($organizationBranchIds, $pinnedBranchIds, $branchHeader)
            : BranchSelection::none();

        if ($branches->denial !== null) {
            return new self(null, $branches->denial);
        }

        return new self(new TenantContext(
            $session->userId,
            $role,
            $resolution->scope,
            $branches->allowedBranchIds,
            $branches->restricted,
            $branches->selectedBranchId,
        ), null);
    }
}
