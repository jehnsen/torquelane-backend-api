<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ShopPurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The shop's own purchase orders, per branch. `$branchId` on create is the
 * branch the stock is for.
 */
final class ShopPurchaseOrderPolicy extends InventoryPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->reads();
    }

    public function view(User $user, ShopPurchaseOrder $order): Response
    {
        return $this->readsIn($order->branch_id);
    }

    public function create(User $user, string $branchId): Response
    {
        return $this->managesIn($branchId);
    }

    /** Edit a draft, issue, cancel, receive. */
    public function progress(User $user, ShopPurchaseOrder $order): Response
    {
        return $this->managesIn($order->branch_id);
    }
}
