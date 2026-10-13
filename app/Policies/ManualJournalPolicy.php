<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\ManualJournal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Journal vouchers: staff of the voucher's branch (else 404) with
 * `ledger:view` to read; writing one, or reversing it, changes the books by
 * hand and needs `ledger:manage`.
 */
final class ManualJournalPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerView));
    }

    public function view(User $user, ManualJournal $journal): Response
    {
        return $this->first($this->visible($this->context()->branchAllowed($journal->branch_id)), $this->capability(Capability::LedgerView));
    }

    /** Post a voucher filed under a branch the caller works in. */
    public function create(User $user, string $branchId): Response
    {
        return $this->first($this->staffOnly(), $this->visible($this->context()->branchAllowed($branchId)), $this->capability(Capability::LedgerManage));
    }

    public function reverse(User $user, ManualJournal $journal): Response
    {
        return $this->first($this->visible($this->context()->branchAllowed($journal->branch_id)), $this->capability(Capability::LedgerManage));
    }
}
