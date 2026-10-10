<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The books (Phase 8) are staff-only. `ledger:view` reads the chart, the
 * journal and the reports (within the caller's branches); `ledger:manage`
 * edits the chart, the posting rules and the export mappings. A chart is the
 * organization's, so an account has no branch to hide it by.
 */
final class AccountPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerView));
    }

    public function view(User $user, Account $account): Response
    {
        return $this->viewAny($user);
    }

    public function create(User $user): Response
    {
        return $this->manage();
    }

    public function update(User $user, Account $account): Response
    {
        return $this->manage();
    }

    /** Re-point posting rules, set the accounting target, map accounts for export. */
    public function configure(User $user): Response
    {
        return $this->manage();
    }

    private function manage(): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerManage));
    }
}
