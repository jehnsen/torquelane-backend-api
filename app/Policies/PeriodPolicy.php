<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Accounting periods. Anyone with `ledger:view` sees where the months stand;
 * closing one needs `ledger:manage` AND the whole organization's books in
 * view (not pinned to some branches): the close checklist reconciles every
 * branch, so a branch-pinned user cannot attest to it.
 */
final class PeriodPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerView));
    }

    public function close(User $user): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->capability(Capability::LedgerManage),
            $this->context()->branchRestricted ? Response::deny('Closing a month reconciles every branch; it cannot be done from a branch-limited sign-in.') : null,
        );
    }
}
