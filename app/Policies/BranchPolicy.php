<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Branches are staff-only. A staff member pinned to some branches
 * (branch_user) cannot see the others at all: 404, not 403.
 */
final class BranchPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly());
    }

    public function view(User $user, Branch $branch): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branch->id)),
        );
    }

    public function create(User $user): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->capability(Capability::OrganizationManage),
        );
    }

    /** Branch details, its branding overrides and its module switches. */
    public function update(User $user, Branch $branch): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branch->id)),
            $this->capability(Capability::SettingsManage),
        );
    }

    public function delete(User $user, Branch $branch): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branch->id)),
            $this->capability(Capability::OrganizationManage),
        );
    }
}
