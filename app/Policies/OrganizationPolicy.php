<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class OrganizationPolicy extends TenantPolicy
{
    public function view(User $user, Organization $organization): Response
    {
        return $this->first(
            $this->visible($organization->id === $this->context()->organizationId()),
            $this->staffOnly(),
        );
    }

    /** Profile, branding and the organization-level module switches. */
    public function update(User $user, Organization $organization): Response
    {
        return $this->first(
            $this->visible($organization->id === $this->context()->organizationId()),
            $this->staffOnly(),
            $this->capability(Capability::OrganizationManage),
        );
    }
}
