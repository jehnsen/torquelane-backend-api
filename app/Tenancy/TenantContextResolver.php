<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\AccountFacts;
use App\Domain\Tenancy\OrganizationFacts;
use App\Domain\Tenancy\SessionFacts;
use App\Domain\Tenancy\TenantResolution;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\User;

/**
 * Loads the stored facts about an authenticated user and hands them to the
 * pure TenantResolution. Runs in the named system context "tenant
 * resolution": the tenant is what is being worked out, so no scope applies
 * yet. Only the user row, its own organization and its own account are read.
 */
final class TenantContextResolver
{
    public function __construct(private readonly TenantManager $tenancy) {}

    public function resolve(User $user, ?string $branchHeader): TenantResolution
    {
        return $this->tenancy->system('tenant resolution', function () use ($user, $branchHeader): TenantResolution {
            $organization = Organization::query()->find($user->organization_id);
            $account = $user->customer_account_id === null
                ? null
                : CustomerAccount::query()->find($user->customer_account_id);

            $branchIds = $organization === null ? [] : array_values(Branch::query()
                ->where('organization_id', $organization->id)
                ->orderBy('name')
                ->get(['id'])
                ->map(fn (Branch $branch): string => $branch->id)
                ->all());

            $pinned = array_values($user->branches()
                ->get(['branches.id'])
                ->map(fn (Branch $branch): string => $branch->id)
                ->all());

            return TenantResolution::build(
                new SessionFacts(
                    $user->id,
                    $user->organization_id,
                    $user->side,
                    // Raw, so an unrecognised stored role is a denial, not a cast error.
                    is_string($role = $user->getRawOriginal('role')) ? $role : null,
                    $user->customer_account_id,
                    $user->isDisabled(),
                ),
                $organization === null ? [] : [new OrganizationFacts($organization->id, $organization->isSuspended())],
                $account === null ? [] : [new AccountFacts($account->id, $account->organization_id, $account->isSuspended())],
                $branchIds,
                $pinned,
                $branchHeader,
            );
        });
    }
}
