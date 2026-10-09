<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\WorkOrders\ApprovalSettingsResolver;
use App\Domain\Invoicing\VatTreatment;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\Organization;

/**
 * Who an invoice is from and to, and how its branch treats VAT, as they stand
 * now. Written onto a draft when it is raised, written again at issue, and
 * frozen from then on (the printed invoice never changes when a TIN or an
 * address is later corrected).
 *
 * The seller is the branch: its BIR-registered name (else the organization's
 * legal name, else its name), business style, TIN (else the organization's)
 * and branch code, address (else the organization's), VAT registration, and
 * the header / footer text its invoices print.
 */
final class InvoiceParties
{
    public function __construct(private readonly ApprovalSettingsResolver $settings) {}

    /**
     * @return array<string, mixed> invoice columns
     */
    public function snapshot(Branch $branch, CustomerAccount $account): array
    {
        $organization = Organization::query()->findOrFail($branch->organization_id);
        $vat = $this->vat($branch, $account);

        return [
            'buyer_name' => $account->registered_name ?? $account->display_name,
            'buyer_tin' => $account->tin,
            'buyer_address' => $account->address,
            'seller_name' => $branch->registered_name ?? $organization->legal_name ?? $organization->name,
            'seller_business_style' => $branch->business_style ?? $branch->brand_name,
            'seller_tin' => $branch->tin ?? $organization->tin,
            'seller_branch_code' => $branch->branch_code,
            'seller_address' => $branch->address ?? $organization->address,
            'seller_vat_registered' => $vat->vatRegistered,
            'seller_header' => $branch->invoice_header,
            'seller_footer' => $branch->invoice_footer,
            'prices_include_vat' => $vat->pricesIncludeVat,
            'vat_rate_pct' => $vat->vatRatePct,
        ];
    }

    /** The branch's registration and price basis; the rate the account's work is billed at there. */
    public function vat(Branch $branch, CustomerAccount $account): VatTreatment
    {
        return new VatTreatment(
            $branch->is_vat_registered,
            $branch->prices_include_vat,
            $branch->is_vat_registered ? $this->settings->forAccount($account, $branch->id)->vatRatePct : '0',
        );
    }
}
