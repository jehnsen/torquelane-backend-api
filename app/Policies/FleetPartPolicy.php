<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Models\CustomerAccount;
use App\Models\FleetPart;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A customer account's own spare parts: scope first (another account's part
 * is 404), then `settings:manage` to change the catalogue, then repair_pms.
 * Stock is not edited here (receiving a purchase order restocks).
 */
final class FleetPartPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->module(Module::RepairPms));
    }

    public function view(User $user, FleetPart $part): Response
    {
        return $this->first($this->visible($this->context()->canReachAccount($part->customer_account_id)), $this->module(Module::RepairPms));
    }

    public function create(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->capability(Capability::SettingsManage),
            $this->module(Module::RepairPms),
        );
    }

    public function update(User $user, FleetPart $part): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($part->customer_account_id)),
            $this->capability(Capability::SettingsManage),
            $this->module(Module::RepairPms),
        );
    }

    public function delete(User $user, FleetPart $part): Response
    {
        return $this->update($user, $part);
    }

    /** The demand forecast for `$account`. */
    public function forecast(User $user, CustomerAccount $account): Response
    {
        return $this->first($this->visible($this->context()->canReachAccount($account->id)), $this->module(Module::RepairPms));
    }
}
