<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Journal entries: staff of a branch the entry touches (a transfer touches two) with `ledger:view`. Others: 404. */
final class JournalEntryPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::LedgerView));
    }

    public function view(User $user, JournalEntry $entry): Response
    {
        $context = $this->context();

        return $this->first(
            $this->visible($context->isStaff() && ($context->branchAllowed($entry->branch_id) || $context->branchAllowed($entry->counter_branch_id))),
            $this->capability(Capability::LedgerView),
        );
    }
}
