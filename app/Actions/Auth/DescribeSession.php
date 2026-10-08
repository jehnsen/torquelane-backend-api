<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Access\AccessMatrix;
use App\Domain\Branding\Branding;
use App\Domain\Modules\ModuleEntitlements;
use App\Domain\Tenancy\TenantContext;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;

/**
 * Builds GET /me for a resolved context. The frontend gates its UI from this
 * and stops carrying its own role matrix: capabilities, modules and branding
 * are the server's answer, not something the browser derives.
 *
 * Read-only, so no transaction. Runs as $context (the middleware's, or the
 * one login just resolved).
 */
final class DescribeSession
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly ModuleGate $modules,
    ) {}

    public function handle(User $user, TenantContext $context): SessionDescription
    {
        return $this->tenancy->actingAs($context, function () use ($user, $context): SessionDescription {
            $organization = Organization::query()->findOrFail($context->organizationId());
            $account = $context->customerAccountId() === null
                ? null
                : CustomerAccount::query()->findOrFail($context->customerAccountId());

            $branches = array_values(Branch::query()->visibleTo($context)->orderBy('name')->get()->all());

            $organizationModules = $this->modules->organizationEnabled();
            $byBranch = [];
            foreach ($this->modules->branchEnabled($context->allowedBranchIds) as $branchId => $enabled) {
                $byBranch[$branchId] = ModuleEntitlements::activeForBranch($organizationModules, $enabled);
            }

            $selected = $context->selectedBranchId === null
                ? null
                : array_values(array_filter($branches, fn (Branch $branch): bool => $branch->id === $context->selectedBranchId))[0] ?? null;

            $branding = Branding::resolve(
                $context->scope,
                $organization->brandMark(),
                $account?->brandMark(),
                $selected?->brandMark(),
                new Branding(config()->string('app.name'), null, null, null),
            );

            return new SessionDescription(
                $user,
                $context,
                $organization,
                $account,
                $branches,
                AccessMatrix::capabilitiesOf($context->role),
                $this->modules->active(),
                $organizationModules,
                $byBranch,
                $branding,
            );
        });
    }
}
