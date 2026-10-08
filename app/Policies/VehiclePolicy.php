<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\Response;

/**
 * Vehicles are core (every module services vehicles); only the PMS views
 * (health) need repair_pms. A portal user reaches the vehicles their account
 * owns now; anything else is 404.
 */
final class VehiclePolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, Vehicle $vehicle): Response
    {
        return $this->first($this->visible($this->sees($vehicle)));
    }

    /** Adding a vehicle to $account (staff: any; portal: their own). */
    public function create(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->capability(Capability::VehicleManage),
        );
    }

    public function update(User $user, Vehicle $vehicle): Response
    {
        return $this->first(
            $this->visible($this->sees($vehicle)),
            $this->capability(Capability::VehicleManage),
        );
    }

    /** Change of owner: a staff decision (the portal side only sees one owner). */
    public function transfer(User $user, Vehicle $vehicle): Response
    {
        return $this->first(
            $this->visible($this->sees($vehicle)),
            $this->staffOnly(),
            $this->capability(Capability::VehicleManage),
        );
    }

    public function viewOwnerships(User $user, Vehicle $vehicle): Response
    {
        return $this->first($this->visible($this->sees($vehicle)), $this->staffOnly());
    }

    public function recordReading(User $user, Vehicle $vehicle): Response
    {
        return $this->first(
            $this->visible($this->sees($vehicle)),
            $this->capability(Capability::VehicleUpdate),
        );
    }

    public function voidReading(User $user, Vehicle $vehicle): Response
    {
        return $this->first(
            $this->visible($this->sees($vehicle)),
            $this->capability(Capability::VehicleManage),
        );
    }

    public function viewHealth(User $user, Vehicle $vehicle): Response
    {
        return $this->first($this->visible($this->sees($vehicle)), $this->module(Module::RepairPms));
    }

    public function viewFleetSummary(User $user): Response
    {
        return $this->first($this->module(Module::RepairPms));
    }

    private function sees(Vehicle $vehicle): bool
    {
        return $this->context()->canReachAccount($vehicle->customer_account_id);
    }
}
