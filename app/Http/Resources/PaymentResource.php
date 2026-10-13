<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Billing\InvoiceParties;
use App\Domain\Access\Capability;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Receivables\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment and where it went. `unallocated_cents` is the customer's credit
 * from it: what it has neither allocated nor refunded (none once void).
 * `seller`, `buyer` and `notices` are what its acknowledgment receipt prints:
 * the frontend lays the receipt out, the wording and the parties are the
 * API's. Money in centavos.
 *
 * @property Payment $resource
 */
final class PaymentResource extends JsonResource
{
    public function __construct(Payment $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;
        $payment->loadMissing(['allocations.invoice', 'refunds', 'customerAccount', 'branch']);
        $parties = app(InvoiceParties::class);
        $context = app(TenantManager::class)->context();
        $staff = $context?->isStaff() ?? false;
        $posted = $payment->status === PaymentStatus::Posted;

        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'status' => $payment->status->value,
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'reference_no' => $payment->reference_no,
            'amount_cents' => $payment->amount_cents,
            'allocated_cents' => $payment->allocatedCents(),
            // Given back out of what was not allocated (refunds that still stand).
            'refunded_cents' => $payment->refundedCents(),
            'unallocated_cents' => $payment->unallocatedCents(),
            'received_on' => $payment->received_on->toDateString(),
            'received_by_name' => $payment->received_by_name,
            'branch_id' => $staff ? $payment->branch_id : null,
            'customer_account_id' => $payment->customer_account_id,
            'customer_name' => $payment->customerAccount->display_name,
            // What its acknowledgment receipt prints: who received the money, from whom, and what the receipt is not.
            'seller' => $parties->seller($payment->organization_id, $payment->branch),
            'buyer' => $parties->buyer($payment->customerAccount),
            'notices' => [Invoicing::NOT_AN_INVOICE, Invoicing::NON_VAT_NOTICE],
            'notes' => $payment->notes,
            'allocations' => array_values($payment->allocations->map(fn (PaymentAllocation $allocation): array => [
                'id' => $allocation->id,
                'invoice_id' => $allocation->invoice_id,
                'invoice_number' => $allocation->invoice->number,
                'amount_cents' => $allocation->amount_cents,
                'allocated_on' => $allocation->allocated_on->toDateString(),
                'allocated_by_name' => $allocation->allocated_by_name,
            ])->all()),
            'voided_at' => $payment->voided_at?->toIso8601ZuluString(),
            'voided_by_name' => $payment->voided_by_name,
            'void_reason' => $payment->void_reason,
            'can_allocate' => $posted && $payment->unallocatedCents() > 0 && $staff && $context->can(Capability::BillingManage),
            'can_void' => $posted && $staff && $context->can(Capability::BillingVoid),
            'created_at' => $payment->created_at->toIso8601ZuluString(),
        ];
    }
}
