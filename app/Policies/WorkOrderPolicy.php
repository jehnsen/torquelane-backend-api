<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Auth\Access\Response;

/**
 * Scope first (an order the caller cannot see is 404), then side, then
 * capability, then the repair module where the order lives. The machine
 * (WorkOrderMachine) decides whether the move itself is legal; the action
 * decides authority beyond the capability (approval bands).
 */
final class WorkOrderPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->module(Module::RepairPms));
    }

    public function view(User $user, WorkOrder $order): Response
    {
        return $this->first($this->visible($this->sees($order)), $this->repairOn($order));
    }

    public function create(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->capability(Capability::WorkOrderCreate),
        );
    }

    /** updateDraft, recordLines, sendForApproval, start, cancel. */
    public function update(User $user, WorkOrder $order): Response
    {
        return $this->act($order, Capability::WorkOrderUpdate);
    }

    public function decide(User $user, WorkOrder $order): Response
    {
        return $this->act($order, Capability::WorkOrderApprove);
    }

    /** Bays and the floor are the shop's. */
    public function schedule(User $user, WorkOrder $order): Response
    {
        return $this->act($order, Capability::WorkOrderUpdate, staffOnly: true);
    }

    /** complete and close. */
    public function complete(User $user, WorkOrder $order): Response
    {
        return $this->act($order, Capability::WorkOrderComplete);
    }

    /** Handing the vehicle back happens at the shop's counter. */
    public function collect(User $user, WorkOrder $order): Response
    {
        return $this->act($order, Capability::WorkOrderUpdate, staffOnly: true);
    }

    public function viewShop(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->module(Module::RepairPms));
    }

    public function lookup(User $user): Response
    {
        return $this->first($this->module(Module::RepairPms));
    }

    /** A new customer and vehicle at the counter, in one go. */
    public function checkIn(User $user): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->capability(Capability::CustomerManage),
            $this->capability(Capability::VehicleManage),
            $this->module(Module::RepairPms),
        );
    }

    private function act(WorkOrder $order, Capability $capability, bool $staffOnly = false): Response
    {
        return $this->first(
            $this->visible($this->sees($order)),
            $staffOnly ? $this->staffOnly() : null,
            $this->capability($capability),
            $this->repairOn($order),
        );
    }

    private function sees(WorkOrder $order): bool
    {
        $context = $this->context();
        if ($context->isPortal()) {
            return $context->customerAccountId() === $order->customer_account_id;
        }

        return $order->branch_id === null || $context->branchAllowed($order->branch_id);
    }

    /**
     * Throws module_disabled when repair is off where the order lives; never
     * denies otherwise. Deferred: it runs only once the order is visible.
     *
     * @return Closure(): null
     */
    private function repairOn(WorkOrder $order): Closure
    {
        return $this->module(Module::RepairPms, $order->branch_id);
    }
}
