<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Access\Capability;
use App\Domain\Receivables\PaymentStatus;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment to a vendor (the cash-out voucher) and the bills it settled.
 * Money in centavos.
 *
 * @property VendorPayment $resource
 */
final class VendorPaymentResource extends JsonResource
{
    public function __construct(VendorPayment $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;
        $payment->loadMissing(['allocations.bill', 'vendor']);
        $can = app(TenantManager::class)->context()?->can(Capability::PayablesManage) ?? false;

        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'status' => $payment->status->value,
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'reference_no' => $payment->reference_no,
            'amount_cents' => $payment->amount_cents,
            'paid_on' => $payment->paid_on->toDateString(),
            'paid_by_name' => $payment->paid_by_name,
            'branch_id' => $payment->branch_id,
            'vendor_id' => $payment->vendor_id,
            'vendor_name' => $payment->vendor->name,
            'notes' => $payment->notes,
            'allocations' => array_values($payment->allocations->map(fn (VendorPaymentAllocation $allocation): array => [
                'id' => $allocation->id,
                'vendor_bill_id' => $allocation->vendor_bill_id,
                'bill_number' => $allocation->bill->number,
                'vendor_invoice_no' => $allocation->bill->vendor_invoice_no,
                'amount_cents' => $allocation->amount_cents,
            ])->all()),
            'voided_at' => $payment->voided_at?->toIso8601ZuluString(),
            'voided_by_name' => $payment->voided_by_name,
            'void_reason' => $payment->void_reason,
            'can_void' => $payment->status === PaymentStatus::Posted && $can,
            'created_at' => $payment->created_at->toIso8601ZuluString(),
        ];
    }
}
