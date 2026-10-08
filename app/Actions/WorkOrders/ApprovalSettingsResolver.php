<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\Approvals\ApprovalSettings;
use App\Models\ApprovalSetting;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\WorkOrder;

/**
 * Folds the three levels into the settings one order runs on:
 * organization defaults → branch override → customer account override, then
 * the branch's VAT registration (not registered bills 0%). An organization
 * with no defaults row runs on ../web's defaults.
 */
final class ApprovalSettingsResolver
{
    public function organizationDefaults(): ApprovalSettings
    {
        $row = ApprovalSetting::query()->whereNull('branch_id')->first();

        return $row === null ? ApprovalSettings::defaults() : ApprovalSettings::defaults()->overriddenBy($row->values());
    }

    public function forBranch(?string $branchId): ApprovalSettings
    {
        return $this->resolve($branchId, null);
    }

    public function forAccount(CustomerAccount $account, ?string $branchId): ApprovalSettings
    {
        return $this->resolve($branchId, $account->approval_threshold_overrides);
    }

    public function forOrder(WorkOrder $order): ApprovalSettings
    {
        $account = CustomerAccount::query()->findOrFail($order->customer_account_id);

        return $this->forAccount($account, $order->branch_id);
    }

    /**
     * @param  array<string, int|string>|null  $accountOverrides
     */
    private function resolve(?string $branchId, ?array $accountOverrides): ApprovalSettings
    {
        $branchOverride = null;
        $vatRegistered = true;
        if ($branchId !== null) {
            $branchOverride = ApprovalSetting::query()->where('branch_id', $branchId)->first()?->values();
            $vatRegistered = Branch::query()->whereKey($branchId)->value('is_vat_registered') !== false;
        }

        return ApprovalSettings::effective($this->organizationDefaults(), $branchOverride, $accountOverrides, $vatRegistered);
    }
}
