<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A branch's detailing menu: categories, packages with their prices and
 * materials, add-ons. Every staff role reads it; `detailing:manage` changes it.
 */
final class DetailingPackagePolicy extends DetailingPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->anywhere(Capability::DetailingView);
    }

    public function viewIn(User $user, string $branchId): Response
    {
        return $this->in($branchId, Capability::DetailingView);
    }

    public function manageIn(User $user, string $branchId): Response
    {
        return $this->in($branchId, Capability::DetailingManage);
    }
}
