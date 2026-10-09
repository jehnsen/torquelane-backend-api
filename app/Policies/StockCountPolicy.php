<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class StockCountPolicy extends InventoryPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->reads();
    }

    public function view(User $user, StockCount $count): Response
    {
        return $this->readsIn($count->branch_id);
    }

    /** Draw a sheet for a location of `$branchId`. */
    public function create(User $user, string $branchId): Response
    {
        return $this->managesIn($branchId);
    }

    /** Enter counts, post, cancel. */
    public function update(User $user, StockCount $count): Response
    {
        return $this->managesIn($count->branch_id);
    }
}
