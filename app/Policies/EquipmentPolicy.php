<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Equipment monitoring is staff-only and belongs to the `equipment` module:
 * side, then scope (another branch's tool is 404), then the module (403
 * `module_disabled`), then the capability. `equipment:view` reads;
 * `equipment:manage` registers tools and logs against them.
 */
final class EquipmentPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->module(Module::Equipment), $this->capability(Capability::EquipmentView));
    }

    public function viewIn(User $user, string $branchId): Response
    {
        return $this->in($branchId, Capability::EquipmentView);
    }

    public function manageIn(User $user, string $branchId): Response
    {
        return $this->in($branchId, Capability::EquipmentManage);
    }

    private function in(string $branchId, Capability $capability): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branchId)),
            $this->module(Module::Equipment, $branchId),
            $this->capability($capability),
        );
    }
}
