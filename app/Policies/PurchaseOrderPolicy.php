<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Models\CustomerAccount;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Scope first (another account's order is 404), then `po:issue` for every
 * move, then repair_pms. Authority over the AMOUNT (issuing within band) is
 * the action's (App\Actions\PurchaseOrders\ProgressPurchaseOrder::send).
 */
final class PurchaseOrderPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->module(Module::RepairPms));
    }

    public function view(User $user, PurchaseOrder $order): Response
    {
        return $this->first($this->visible($this->context()->canReachAccount($order->customer_account_id)), $this->module(Module::RepairPms));
    }

    /** Raising purchase requests from `$account`'s forecast. */
    public function create(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->capability(Capability::PoIssue),
            $this->module(Module::RepairPms),
        );
    }

    /** send, receive, cancel. */
    public function progress(User $user, PurchaseOrder $order): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($order->customer_account_id)),
            $this->capability(Capability::PoIssue),
            $this->module(Module::RepairPms),
        );
    }
}
