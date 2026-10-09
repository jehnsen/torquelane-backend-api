<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\CustomerAccount;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Invoices: scope first (an invoice outside the caller's branches, another
 * account's, or, for the portal, a draft is 404), then side (raising,
 * issuing and voiding are the shop's), then the capability. Billing is core:
 * no module gates it. The billing queue, aging and revenue are staff reads.
 */
final class InvoicePolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->capability(Capability::BillingView));
    }

    public function view(User $user, Invoice $invoice): Response
    {
        return $this->first($this->visible($this->sees($invoice)), $this->capability(Capability::BillingView));
    }

    /** Raise a draft for an account, in a branch the caller works in. */
    public function create(User $user, CustomerAccount $account, string $branchId): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branchId)),
            $this->capability(Capability::BillingManage),
        );
    }

    /** Edit, discard or issue a draft. */
    public function update(User $user, Invoice $invoice): Response
    {
        return $this->act($invoice, Capability::BillingManage);
    }

    public function void(User $user, Invoice $invoice): Response
    {
        return $this->act($invoice, Capability::BillingVoid);
    }

    /** Staff-only reads: the billing queue, receivables aging, revenue. */
    public function viewReceivables(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::BillingView));
    }

    private function act(Invoice $invoice, Capability $capability): Response
    {
        return $this->first($this->visible($this->sees($invoice)), $this->staffOnly(), $this->capability($capability));
    }

    private function sees(Invoice $invoice): bool
    {
        $context = $this->context();
        if ($context->isPortal()) {
            return $invoice->customer_account_id === $context->customerAccountId() && $invoice->status !== InvoiceStatus::Draft;
        }

        return $context->branchAllowed($invoice->branch_id);
    }
}
