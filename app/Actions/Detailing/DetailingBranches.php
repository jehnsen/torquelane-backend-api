<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Domain\Modules\Module;
use App\Models\Branch;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Which branch a detailing request works in. Detailing is a branch's own
 * business (its menu, its pool, its bookings), so every write and every
 * branch-shaped read names one: the `branch_id` given, else the branch the
 * session has selected, else the only detailing branch it can reach.
 */
final class DetailingBranches
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly ModuleGate $modules,
    ) {}

    /**
     * The detailing branches the session's lists cover: those it may work in
     * (the selected one, or all it is allowed) where the module is active.
     *
     * @return list<string>
     */
    public function reachable(): array
    {
        $context = $this->tenancy->require();
        if (! $context->isStaff()) {
            return [];
        }

        return array_values(array_intersect($context->branchFilter(), $this->modules->branchesWith(Module::Detailing)));
    }

    /** The branch to work in. A branch the caller is not allowed is as missing as one that does not exist. */
    public function resolve(?string $branchId): Branch
    {
        $context = $this->tenancy->require();
        if (! $context->isStaff()) {
            // Detailing is the shop's side: refuse before saying anything about its branches.
            throw new AuthorizationException('Only staff can do this.');
        }
        if ($branchId !== null) {
            if (! $context->branchAllowed($branchId)) {
                throw (new ModelNotFoundException)->setModel(Branch::class, [$branchId]);
            }

            return Branch::query()->findOrFail($branchId);
        }

        $reachable = $this->reachable();
        if (count($reachable) === 1) {
            return Branch::query()->findOrFail($reachable[0]);
        }
        if ($context->selectedBranchId !== null) {
            return Branch::query()->findOrFail($context->selectedBranchId);
        }

        throw ValidationException::withMessages(['branch_id' => $reachable === []
            ? 'No branch you work in offers detailing.'
            : 'Say which branch: more than one of yours offers detailing.']);
    }
}
