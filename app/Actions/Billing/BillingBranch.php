<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Tenancy\TenantManager;
use Illuminate\Validation\ValidationException;

/**
 * Which branch a typed-in invoice or a payment belongs to: the one named,
 * else the one selected (X-Branch-Id), else the caller's only branch. Staff
 * only; for a portal session it answers what was asked (the policy refuses
 * the portal before anything is written).
 */
final class BillingBranch
{
    public function __construct(private readonly TenantManager $tenancy) {}

    public function resolve(?string $requested): string
    {
        $context = $this->tenancy->require();
        if ($context->isPortal()) {
            return $requested ?? '';
        }

        $branchId = $requested ?? $context->selectedBranchId ?? (count($context->allowedBranchIds) === 1 ? $context->allowedBranchIds[0] : null);
        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => 'Choose the branch this belongs to.']);
        }

        return $branchId;
    }
}
