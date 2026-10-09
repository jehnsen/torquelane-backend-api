<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/** The movement ledger (reads only: moves are appended by the documents that cause them). */
final class StockMovePolicy extends InventoryPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->reads();
    }
}
