<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Capability;
use App\Domain\Access\Role;

/**
 * Everything a request may act on, resolved once by the tenant middleware
 * from the authenticated user (never from request input, apart from the
 * validated branch selection).
 */
final readonly class TenantContext
{
    /**
     * @param  list<string>  $allowedBranchIds  for staff; empty for portal
     */
    public function __construct(
        public string $userId,
        public Role $role,
        public TenantScope $scope,
        public array $allowedBranchIds,
        /** True when the user is pinned to specific branches (branch_user rows). */
        public bool $branchRestricted,
        /** One allowed branch, or null for "all allowed branches" (and for portal). */
        public ?string $selectedBranchId,
    ) {}

    public function organizationId(): string
    {
        return $this->scope->organizationId;
    }

    public function customerAccountId(): ?string
    {
        return $this->scope->customerAccountId;
    }

    public function isStaff(): bool
    {
        return $this->scope->isStaff();
    }

    public function isPortal(): bool
    {
        return ! $this->scope->isStaff();
    }

    public function can(Capability $capability): bool
    {
        return AccessMatrix::can($this->role, $capability);
    }

    public function branchAllowed(?string $branchId): bool
    {
        return $branchId !== null && $this->isStaff() && in_array($branchId, $this->allowedBranchIds, true);
    }

    /**
     * The branches a list should show: the selected one, or every allowed one.
     *
     * @return list<string>
     */
    public function branchFilter(): array
    {
        return $this->selectedBranchId !== null ? [$this->selectedBranchId] : $this->allowedBranchIds;
    }

    /** Whether this session may act on an account: portal only on its own. */
    public function canReachAccount(string $customerAccountId): bool
    {
        return $this->isStaff() || $this->scope->customerAccountId === $customerAccountId;
    }
}
