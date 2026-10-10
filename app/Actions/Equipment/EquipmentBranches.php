<?php

declare(strict_types=1);

namespace App\Actions\Equipment;

use App\Domain\Modules\Module;
use App\Models\Branch;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Which branch an equipment request works in: the `branch_id` given, else the
 * branch the session has selected, else the only branch it can reach where the
 * `equipment` module is on. A portal session is refused before anything is
 * said about branches.
 */
final class EquipmentBranches
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly ModuleGate $modules,
    ) {}

    public function resolve(?string $branchId): Branch
    {
        $context = $this->tenancy->require();
        if (! $context->isStaff()) {
            throw new AuthorizationException('Only staff can do this.');
        }
        if ($branchId !== null) {
            if (! $context->branchAllowed($branchId)) {
                throw (new ModelNotFoundException)->setModel(Branch::class, [$branchId]);
            }

            return Branch::query()->findOrFail($branchId);
        }

        $reachable = array_values(array_intersect($context->branchFilter(), $this->modules->branchesWith(Module::Equipment)));
        if (count($reachable) === 1) {
            return Branch::query()->findOrFail($reachable[0]);
        }
        if ($context->selectedBranchId !== null) {
            return Branch::query()->findOrFail($context->selectedBranchId);
        }

        throw ValidationException::withMessages(['branch_id' => $reachable === []
            ? 'No branch you work in monitors equipment.'
            : 'Say which branch: more than one of yours monitors equipment.']);
    }
}
