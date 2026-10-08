<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Models\ServiceTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The PMS catalogue: readable by every session beneath the organization
 * (portal users see what their vehicles are measured against), written by
 * staff with settings:manage. All of it needs repair_pms.
 */
final class ServiceTaskPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->module(Module::RepairPms));
    }

    public function view(User $user, ServiceTask $task): Response
    {
        return $this->first($this->module(Module::RepairPms));
    }

    public function create(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::SettingsManage), $this->module(Module::RepairPms));
    }

    public function update(User $user, ServiceTask $task): Response
    {
        return $this->create($user);
    }

    public function delete(User $user, ServiceTask $task): Response
    {
        return $this->create($user);
    }
}
