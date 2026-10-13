<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Access\Capability;
use App\Models\WithholdingCertificate;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A BIR 2307 received from a customer: the tax they withheld at source from
 * one invoice. Money in centavos.
 *
 * @property WithholdingCertificate $resource
 */
final class WithholdingCertificateResource extends JsonResource
{
    public function __construct(WithholdingCertificate $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $certificate = $this->resource;
        $certificate->loadMissing(['invoice', 'customerAccount']);
        $context = app(TenantManager::class)->context();
        $staff = $context?->isStaff() ?? false;

        return [
            'id' => $certificate->id,
            'status' => $certificate->status->value,
            'certificate_no' => $certificate->certificate_no,
            'atc_code' => $certificate->atc_code,
            'rate_pct' => (string) $certificate->rate_pct->strippedOfTrailingZeros(),
            'tax_base_cents' => $certificate->tax_base_cents,
            'amount_cents' => $certificate->amount_cents,
            'received_on' => $certificate->received_on->toDateString(),
            'period_from' => $certificate->period_from->toDateString(),
            'period_to' => $certificate->period_to->toDateString(),
            'notes' => $certificate->notes,
            'branch_id' => $staff ? $certificate->branch_id : null,
            'customer_account_id' => $certificate->customer_account_id,
            'customer_name' => $certificate->customerAccount->display_name,
            'customer_tin' => $certificate->customerAccount->tin,
            'invoice_id' => $certificate->invoice_id,
            'invoice_number' => $certificate->invoice->number,
            'recorded_by_name' => $certificate->recorded_by_name,
            'voided_at' => $certificate->voided_at?->toIso8601ZuluString(),
            'voided_by_name' => $certificate->voided_by_name,
            'void_reason' => $certificate->void_reason,
            'can_void' => $certificate->isStanding() && $staff && $context->can(Capability::BillingVoid),
            'created_at' => $certificate->created_at->toIso8601ZuluString(),
        ];
    }
}
