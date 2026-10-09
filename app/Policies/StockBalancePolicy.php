<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Stock on hand, low-stock alerts and the reorder view (reads only: balances change through moves). */
final class StockBalancePolicy extends InventoryPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->reads();
    }
}
