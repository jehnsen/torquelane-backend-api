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
    /** @var array<string, Organization> by id, for the request */
    private array $organizations = [];

    public function __construct(private readonly ApprovalSettingsResolver $settings) {}

    /**
     * Who a receipt or a statement is from, as it stands now: the branch's
     * registered details (else the organization's), or the organization's own
     * when no branch is named. The frontend prints these; it never looks them
     * up or composes them itself (R2).
     *
     * @return array{name: string, business_style: string|null, tin: string|null, branch_code: string|null, tin_printed: string|null, address: string|null}
     */
    public function seller(string $organizationId, ?Branch $branch): array
    {
        $organization = $this->organization($organizationId);
        $tin = $branch->tin ?? $organization->tin;

        return [
            'name' => $branch->registered_name ?? $organization->legal_name ?? $organization->name,
            'business_style' => $branch->business_style ?? $branch?->brand_name,
            'tin' => $tin,
            'branch_code' => $branch?->branch_code,
            'tin_printed' => self::printedTin($tin, $branch?->branch_code),
            'address' => $branch->address ?? $organization->address,
        ];
    }

    /**
     * @return array{name: string, tin: string|null, address: string|null}
     */
    public function buyer(CustomerAccount $account): array
    {
        return ['name' => $account->registered_name ?? $account->display_name, 'tin' => $account->tin, 'address' => $account->address];
    }

    /** `123-456-789-00002`: the TIN with the branch code, as BIR documents print it. */
    public static function printedTin(?string $tin, ?string $branchCode): ?string
    {
        if ($tin === null) {
            return null;
        }

        return $branchCode === null ? $tin : $tin.'-'.str_pad($branchCode, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed> invoice columns
     */
    public function snapshot(Branch $branch, CustomerAccount $account): array
    {
        $organization = $this->organization($branch->organization_id);
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

    private function organization(string $organizationId): Organization
    {
        return $this->organizations[$organizationId] ??= Organization::query()->findOrFail($organizationId);
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
