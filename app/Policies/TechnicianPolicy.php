<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Staff-only, branch-owned. Outside the caller's allowed branches: 404.
 */
final class TechnicianPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly());
    }

    public function view(User $user, Technician $technician): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($technician->branch_id)),
        );
    }

    /** $branchId is the branch the new technician will belong to. */
    public function create(User $user, string $branchId): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->capability(Capability::SettingsManage),
            $this->visible($this->context()->branchAllowed($branchId)),
        );
    }

    public function update(User $user, Technician $technician): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($technician->branch_id)),
            $this->capability(Capability::SettingsManage),
        );
    }

    public function delete(User $user, Technician $technician): Response
    {
        return $this->update($user, $technician);
    }
}
