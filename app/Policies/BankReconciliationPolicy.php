<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\BankReconciliation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Bank reconciliations. An account's lines are every branch's, so a
 * reconciliation is the whole organization's: it is kept to a session that is
 * not branch-limited (as closing a month is). `ledger:view` reads;
 * `ledger:manage` opens, ticks, completes and discards.
 */
final class BankReconciliationPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerView), $this->wholeOrganization());
    }

    public function view(User $user, BankReconciliation $reconciliation): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerView), $this->wholeOrganization());
    }

    public function manage(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerManage), $this->wholeOrganization());
    }

    private function wholeOrganization(): ?Response
    {
        return $this->context()->branchRestricted
            ? Response::deny('A bank account is every branch\'s; it cannot be reconciled from a branch-limited sign-in.')
            : null;
    }
}
