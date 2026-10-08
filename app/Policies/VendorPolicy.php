<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\Response;

/**
 * The provider's vendor list: staff only (../web edits it provider-side
 * only), changed with `settings:manage`.
 */
final class VendorPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly());
    }

    public function view(User $user, Vendor $vendor): Response
    {
        return $this->first($this->staffOnly());
    }

    public function create(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::SettingsManage));
    }

    public function update(User $user, Vendor $vendor): Response
    {
        return $this->create($user);
    }

    public function delete(User $user, Vendor $vendor): Response
    {
        return $this->create($user);
    }
}
