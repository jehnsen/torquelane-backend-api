<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directory\DirectoryQueries;
use App\Actions\Modules\SetModule;
use App\Domain\Modules\Module;
use App\Http\Requests\SetModuleRequest;
use App\Models\Branch;
use App\Models\Organization;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Module switches. Active in a branch = enabled for the organization AND the
 * branch.
 */
final class ModuleController
{
    /**
     * Module entitlements
     *
     * Every module with its organization switch and, per branch the caller can
     * reach, the branch switch and whether it is active there. Staff only.
     */
    public function index(TenantManager $tenancy, ModuleGate $modules): JsonResponse
    {
        $context = $tenancy->require();
        Gate::authorize('viewAny', Branch::class);

        $organization = $modules->organizationEnabled();
        $branches = $modules->branchEnabled($context->allowedBranchIds);

        $data = array_map(fn (Module $module): array => [
            'module' => $module->value,
            'label' => $module->label(),
            'organization_enabled' => in_array($module, $organization, true),
            'branches' => array_map(fn (string $branchId): array => [
                'branch_id' => $branchId,
                'enabled' => in_array($module, $branches[$branchId], true),
                'active' => in_array($module, $organization, true) && in_array($module, $branches[$branchId], true),
            ], array_keys($branches)),
        ], Module::cases());

        return new JsonResponse(['data' => $data]);
    }

    /**
     * Switch a module for the organization
     *
     * Needs `organization:manage`. Switching it off turns it off in every
     * branch at once.
     */
    public function updateOrganization(SetModuleRequest $request, Module $module, DirectoryQueries $queries, SetModule $set): JsonResponse
    {
        $organization = $queries->organization();
        Gate::authorize('update', $organization);

        $row = $set->forOrganization($module, $request->boolean('enabled'));

        return new JsonResponse(['data' => ['module' => $module->value, 'organization_enabled' => $row->enabled]]);
    }

    /**
     * Switch a module for a branch
     *
     * Needs `settings:manage` and access to the branch. Has no effect while
     * the organization switch is off.
     */
    public function updateBranch(SetModuleRequest $request, Branch $branch, Module $module, ModuleGate $modules, SetModule $set): JsonResponse
    {
        Gate::authorize('update', $branch);

        $row = $set->forBranch($branch, $module, $request->boolean('enabled'));

        return new JsonResponse(['data' => [
            'module' => $module->value,
            'branch_id' => $branch->id,
            'enabled' => $row->enabled,
            'active' => in_array($module, $modules->active($branch->id), true),
        ]]);
    }
}
