<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Credit notes: scope first (another account's, or outside the caller's
 * branches, is 404), then side (a credit is the shop's decision), then the
 * capability. Reading needs `billing:view`; issuing or voiding one takes
 * sales back, so it needs `billing:void`, like voiding the invoice would.
 */
final class CreditNotePolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->capability(Capability::BillingView));
    }

    public function view(User $user, CreditNote $note): Response
    {
        return $this->first($this->visible($this->sees($note->customer_account_id, $note->branch_id)), $this->capability(Capability::BillingView));
    }

    /** Credit an invoice the caller can see. */
    public function create(User $user, Invoice $invoice): Response
    {
        return $this->first($this->visible($this->sees($invoice->customer_account_id, $invoice->branch_id)), $this->staffOnly(), $this->capability(Capability::BillingVoid));
    }

    public function void(User $user, CreditNote $note): Response
    {
        return $this->first($this->visible($this->sees($note->customer_account_id, $note->branch_id)), $this->staffOnly(), $this->capability(Capability::BillingVoid));
    }

    private function sees(string $accountId, string $branchId): bool
    {
        $context = $this->context();

        return $context->isPortal() ? $accountId === $context->customerAccountId() : $context->branchAllowed($branchId);
    }
}
