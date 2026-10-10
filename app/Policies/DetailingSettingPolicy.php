<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A branch's detailing settings, blocked slots and display tokens: the
 * source's "super admin" tier. Read with `detailing:view` (the tokens with
 * `detailing:manage`), changed with `detailing:manage`.
 */
final class DetailingSettingPolicy extends DetailingPolicy
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
