<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Auth\Access\Response;

/**
 * Vendor bills: staff of the bill's branch (another branch's is 404; payables
 * have no portal side), then `payables:view` to read and `payables:manage` to
 * record or void.
 */
final class VendorBillPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::PayablesView));
    }

    public function view(User $user, VendorBill $bill): Response
    {
        return $this->first($this->visible($this->context()->branchAllowed($bill->branch_id)), $this->capability(Capability::PayablesView));
    }

    /** Record a bill for a branch the caller works in. */
    public function create(User $user, string $branchId): Response
    {
        return $this->first($this->staffOnly(), $this->visible($this->context()->branchAllowed($branchId)), $this->capability(Capability::PayablesManage));
    }

    public function void(User $user, VendorBill $bill): Response
    {
        return $this->first($this->visible($this->context()->branchAllowed($bill->branch_id)), $this->capability(Capability::PayablesManage));
    }
}
