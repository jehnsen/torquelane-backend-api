<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Fiscal years. A year's revenue, expenses and profit are the whole
 * organization's, so even reading them needs the whole organization's books in
 * view (not pinned to some branches) with `ledger:view`; closing one needs
 * `ledger:manage` (the closing entry empties every branch's revenue and
 * expense accounts).
 */
final class FiscalYearPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerView), $this->wholeOrganization());
    }

    public function close(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerManage), $this->wholeOrganization());
    }

    private function wholeOrganization(): ?Response
    {
        return $this->context()->branchRestricted
            ? Response::deny('A fiscal year is every branch\'s books; it cannot be read or closed from a branch-limited sign-in.')
            : null;
    }
}
