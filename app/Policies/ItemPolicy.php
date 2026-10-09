<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Items are organization-wide master data: any staff with `inventory:view`
 * reads them, `inventory:manage` changes them. A branch's settings for an
 * item are changed only in a branch the caller may work in.
 */
final class ItemPolicy extends InventoryPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->reads();
    }

    public function view(User $user, Item $item): Response
    {
        return $this->reads();
    }

    public function create(User $user): Response
    {
        return $this->manages();
    }

    public function update(User $user, Item $item): Response
    {
        return $this->manages();
    }

    public function setBranchSettings(User $user, Item $item, string $branchId): Response
    {
        return $this->managesIn($branchId);
    }
}
