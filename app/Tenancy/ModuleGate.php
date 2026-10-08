<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Modules\Module;
use App\Domain\Modules\ModuleEntitlements;
use App\Domain\Tenancy\TenantContext;
use App\Exceptions\ModuleDisabledException;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\OrganizationModule;

/**
 * Module entitlement, the third leg of every policy check (capability + scope
 * + module). A module is active for a branch only when enabled for the
 * organization AND the branch (ModuleEntitlements).
 *
 * Which branch: the one given, else the session's selected branch, else (a
 * staff session across "all", or a portal user) any branch the session can
 * reach.
 */
final class ModuleGate
{
    public function __construct(private readonly TenantManager $tenancy) {}

    public function ensure(Module $module, ?string $branchId = null): void
    {
        if (! in_array($module, $this->active($branchId), true)) {
            throw new ModuleDisabledException(sprintf('%s is not enabled for this branch.', $module->label()));
        }
    }

    /**
     * @return list<Module>
     */
    public function active(?string $branchId = null): array
    {
        $context = $this->tenancy->require();
        $organization = $this->organizationEnabled();
        $byBranch = $this->branchEnabled($this->reachableBranches($context));

        $branchId ??= $context->selectedBranchId;
        if ($branchId !== null) {
            return ModuleEntitlements::activeForBranch($organization, $byBranch[$branchId] ?? []);
        }

        return ModuleEntitlements::activeForAny($organization, $byBranch);
    }

    /**
     * @return list<Module>
     */
    public function organizationEnabled(): array
    {
        return array_values(OrganizationModule::query()
            ->where('enabled', true)
            ->get()
            ->map(fn (OrganizationModule $row): Module => $row->module)
            ->all());
    }

    /**
     * Enabled modules per branch, every given branch present (possibly empty).
     *
     * @param  list<string>  $branchIds
     * @return array<string, list<Module>>
     */
    public function branchEnabled(array $branchIds): array
    {
        $enabled = array_fill_keys($branchIds, []);

        $rows = BranchModule::query()
            ->whereIn('branch_id', $branchIds)
            ->where('enabled', true)
            ->get();

        foreach ($rows as $row) {
            $enabled[$row->branch_id][] = $row->module;
        }

        return $enabled;
    }

    /**
     * @return list<string>
     */
    private function reachableBranches(TenantContext $context): array
    {
        if ($context->isStaff()) {
            return $context->allowedBranchIds;
        }

        return array_values(Branch::query()->where('status', 'active')->get(['id'])->map(fn (Branch $branch): string => $branch->id)->all());
    }
}
