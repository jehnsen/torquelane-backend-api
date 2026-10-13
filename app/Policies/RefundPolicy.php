<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Refunds: scope first (another account's, or outside the caller's branches,
 * is 404), then side, then the capability. Reading needs `billing:view`;
 * paying money back, or voiding that, needs `billing:void`.
 */
final class RefundPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->capability(Capability::BillingView));
    }

    public function view(User $user, Refund $refund): Response
    {
        return $this->first($this->visible($this->sees($refund->customer_account_id, $refund->branch_id)), $this->capability(Capability::BillingView));
    }

    /** Refund out of a payment or a credit note the caller can see. */
    public function create(User $user, Payment|CreditNote $source): Response
    {
        return $this->first($this->visible($this->sees($source->customer_account_id, $source->branch_id)), $this->staffOnly(), $this->capability(Capability::BillingVoid));
    }

    public function void(User $user, Refund $refund): Response
    {
        return $this->first($this->visible($this->sees($refund->customer_account_id, $refund->branch_id)), $this->staffOnly(), $this->capability(Capability::BillingVoid));
    }

    private function sees(string $accountId, string $branchId): bool
    {
        $context = $this->context();

        return $context->isPortal() ? $accountId === $context->customerAccountId() : $context->branchAllowed($branchId);
    }
}
