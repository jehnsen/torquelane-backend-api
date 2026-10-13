<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\User;
use App\Models\VendorPayment;
use Illuminate\Auth\Access\Response;

/** Payments to vendors: staff of the payment's branch (else 404), `payables:view` to read, `payables:manage` to pay or void. */
final class VendorPaymentPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::PayablesView));
    }

    public function view(User $user, VendorPayment $payment): Response
    {
        return $this->first($this->visible($this->context()->branchAllowed($payment->branch_id)), $this->capability(Capability::PayablesView));
    }

    /** Pay a vendor from a branch the caller works in. */
    public function create(User $user, string $branchId): Response
    {
        return $this->first($this->staffOnly(), $this->visible($this->context()->branchAllowed($branchId)), $this->capability(Capability::PayablesManage));
    }

    public function void(User $user, VendorPayment $payment): Response
    {
        return $this->first($this->visible($this->context()->branchAllowed($payment->branch_id)), $this->capability(Capability::PayablesManage));
    }
}
