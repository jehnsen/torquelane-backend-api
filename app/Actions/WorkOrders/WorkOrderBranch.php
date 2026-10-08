<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\Modules\Module;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;
use Illuminate\Validation\ValidationException;

/**
 * Which branch new repair work lands in. Staff work in a branch: the one they
 * name, else the one selected (X-Branch-Id), else the only branch they reach
 * where repair is on. A portal
 * request names none — it waits to be taken in by staff. Either way the
 * repair module must be on where the work lands.
 */
final class WorkOrderBranch
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly ModuleGate $modules,
    ) {}

    public function forNewWork(?string $requested): ?string
    {
        $context = $this->tenancy->require();
        if ($context->isPortal()) {
            $this->modules->ensure(Module::RepairPms);

            return null;
        }

        $repairBranches = $this->modules->branchesWith(Module::RepairPms);
        $branchId = $requested ?? $context->selectedBranchId ?? (count($repairBranches) === 1 ? $repairBranches[0] : null);
        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => 'Choose the branch taking this work in.']);
        }
        if (! $context->branchAllowed($branchId)) {
            throw ValidationException::withMessages(['branch_id' => 'You do not work in that branch.']);
        }
        $this->modules->ensure(Module::RepairPms, $branchId);

        return $branchId;
    }

    /** An order already in a branch stays there; a portal request is taken into the acting staff member's branch. */
    public function takeIn(?string $current): ?string
    {
        if ($current !== null) {
            $this->modules->ensure(Module::RepairPms, $current);

            return $current;
        }

        return $this->tenancy->require()->isStaff() ? $this->forNewWork(null) : null;
    }
}
