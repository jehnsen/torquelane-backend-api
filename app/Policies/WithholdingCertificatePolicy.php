<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WithholdingCertificate;
use Illuminate\Auth\Access\Response;

/**
 * Withholding certificates received (2307s): scope first (404), then side,
 * then the capability. Recording one settles part of an invoice, like taking
 * a payment (`billing:manage`); voiding one is `billing:void`.
 */
final class WithholdingCertificatePolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->capability(Capability::BillingView));
    }

    public function view(User $user, WithholdingCertificate $certificate): Response
    {
        return $this->first($this->visible($this->sees($certificate->customer_account_id, $certificate->branch_id)), $this->capability(Capability::BillingView));
    }

    /** Record a certificate against an invoice the caller can see. */
    public function create(User $user, Invoice $invoice): Response
    {
        return $this->first($this->visible($this->sees($invoice->customer_account_id, $invoice->branch_id)), $this->staffOnly(), $this->capability(Capability::BillingManage));
    }

    public function void(User $user, WithholdingCertificate $certificate): Response
    {
        return $this->first($this->visible($this->sees($certificate->customer_account_id, $certificate->branch_id)), $this->staffOnly(), $this->capability(Capability::BillingVoid));
    }

    private function sees(string $accountId, string $branchId): bool
    {
        $context = $this->context();

        return $context->isPortal() ? $accountId === $context->customerAccountId() : $context->branchAllowed($branchId);
    }
}
