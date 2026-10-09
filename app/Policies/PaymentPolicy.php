<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\CustomerAccount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Payments: scope first (another account's, or outside the caller's
 * branches, is 404), then side (money is recorded at the shop), then the
 * capability.
 */
final class PaymentPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->capability(Capability::BillingView));
    }

    public function view(User $user, Payment $payment): Response
    {
        return $this->first($this->visible($this->sees($payment)), $this->capability(Capability::BillingView));
    }

    /** Record a payment from an account, at a branch the caller works in. */
    public function create(User $user, CustomerAccount $account, string $branchId): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branchId)),
            $this->capability(Capability::BillingManage),
        );
    }

    /** Apply its unallocated credit. */
    public function allocate(User $user, Payment $payment): Response
    {
        return $this->first($this->visible($this->sees($payment)), $this->staffOnly(), $this->capability(Capability::BillingManage));
    }

    public function void(User $user, Payment $payment): Response
    {
        return $this->first($this->visible($this->sees($payment)), $this->staffOnly(), $this->capability(Capability::BillingVoid));
    }

    private function sees(Payment $payment): bool
    {
        $context = $this->context();
        if ($context->isPortal()) {
            return $payment->customer_account_id === $context->customerAccountId();
        }

        return $context->branchAllowed($payment->branch_id);
    }
}
