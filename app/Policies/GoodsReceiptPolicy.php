<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class GoodsReceiptPolicy extends InventoryPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->reads();
    }

    public function view(User $user, GoodsReceipt $receipt): Response
    {
        return $this->readsIn($receipt->branch_id);
    }

    public function void(User $user, GoodsReceipt $receipt): Response
    {
        return $this->managesIn($receipt->branch_id);
    }
}
