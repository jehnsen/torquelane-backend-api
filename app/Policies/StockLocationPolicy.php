<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class StockLocationPolicy extends InventoryPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->reads();
    }

    public function view(User $user, StockLocation $location): Response
    {
        return $this->readsIn($location->branch_id);
    }

    /** The first count of items in this location. */
    public function recordOpening(User $user, StockLocation $location): Response
    {
        return $this->managesIn($location->branch_id);
    }
}
