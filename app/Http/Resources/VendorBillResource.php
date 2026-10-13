<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Access\Capability;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Payables\BillStatus;
use App\Domain\Receivables\Aging;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Models\VendorBill;
use App\Models\VendorBillLine;
use App\Models\VendorPaymentAllocation;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor bill with its lines and the payments against it. `payable_cents`
 * is the total less what was withheld at source; `balance_cents` what is
 * still owed the vendor. Money in centavos.
 *
 * @property VendorBill $resource
 */
final class VendorBillResource extends JsonResource
{
    public function __construct(VendorBill $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bill = $this->resource;
        $bill->loadMissing(['lines.account', 'allocations.payment', 'vendor']);
        $can = app(TenantManager::class)->context()?->can(Capability::PayablesManage) ?? false;
        $overdue = $bill->status->isOpen() ? max(0, Aging::daysPastDue($bill->due_date->toDateString(), Calendar::toDate(CarbonImmutable::now()))) : 0;

        return [
            'id' => $bill->id,
            'number' => $bill->number,
            'status' => $bill->status->value,
            'status_label' => $bill->status->label(),
            'branch_id' => $bill->branch_id,
            'vendor_id' => $bill->vendor_id,
            'vendor_name' => $bill->vendor->name,
            'vendor_invoice_no' => $bill->vendor_invoice_no,
            'bill_date' => $bill->bill_date->toDateString(),
            'due_date' => $bill->due_date->toDateString(),
            'days_overdue' => $overdue,
            'vendor' => [
                'name' => $bill->vendor_name,
                'tin' => $bill->vendor_tin,
                'address' => $bill->vendor_address,
                'vat_registered' => $bill->vendor_vat_registered,
            ],
            'prices_include_vat' => $bill->prices_include_vat,
            'vat_rate_pct' => (string) $bill->vat_rate_pct->strippedOfTrailingZeros(),
            'totals' => [
                'vatable_purchases_cents' => $bill->vatable_purchases_cents,
                'vat_exempt_purchases_cents' => $bill->vat_exempt_purchases_cents,
                'zero_rated_purchases_cents' => $bill->zero_rated_purchases_cents,
                'non_vat_purchases_cents' => $bill->non_vat_purchases_cents,
                'net_purchases_cents' => $bill->netPurchasesCents(),
                'input_vat_cents' => $bill->input_vat_cents,
                'total_cents' => $bill->total_cents,
            ],
            'withholding' => $bill->atc_code === null ? null : [
                'atc_code' => $bill->atc_code,
                'rate_pct' => (string) $bill->withholding_rate_pct?->strippedOfTrailingZeros(),
                'tax_base_cents' => $bill->withholding_base_cents,
                'amount_cents' => $bill->withholding_cents,
            ],
            'payable_cents' => $bill->payable_cents,
            'paid_cents' => $bill->paid_cents,
            'balance_cents' => $bill->balanceCents(),
            'notes' => $bill->notes,
            'lines' => array_values($bill->lines->map(fn (VendorBillLine $line): array => [
                'id' => $line->id,
                'position' => $line->position,
                'kind' => $line->kind->value,
                'description' => $line->description,
                'goods_receipt_line_id' => $line->goods_receipt_line_id,
                'account_id' => $line->account_id,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'quantity' => Invoicing::quantity((string) $line->quantity),
                'unit_cost_cents' => $line->unit_cost_cents,
                'tax_class' => $line->tax_class->value,
                'line_total_cents' => $line->line_total_cents,
                'net_cents' => $line->net_cents,
                // Goods received into stock: what the receipt left in GR/IR, and how far the vendor's price is from it.
                'received_value_cents' => $line->received_value_cents,
                'price_variance_cents' => $line->received_value_cents === null ? null : $line->net_cents - $line->received_value_cents,
            ])->all()),
            'payments' => array_values($bill->allocations->map(fn (VendorPaymentAllocation $allocation): array => [
                'allocation_id' => $allocation->id,
                'vendor_payment_id' => $allocation->vendor_payment_id,
                'number' => $allocation->payment->number,
                'method' => $allocation->payment->method->value,
                'reference_no' => $allocation->payment->reference_no,
                'amount_cents' => $allocation->amount_cents,
                'paid_on' => $allocation->payment->paid_on->toDateString(),
                'payment_status' => $allocation->payment->status->value,
                'counts' => $allocation->payment->status === PaymentStatus::Posted,
            ])->all()),
            'created_by_name' => $bill->created_by_name,
            'voided_at' => $bill->voided_at?->toIso8601ZuluString(),
            'voided_by_name' => $bill->voided_by_name,
            'void_reason' => $bill->void_reason,
            'can_pay' => $bill->status->isOpen() && $can,
            'can_void' => $bill->status === BillStatus::Posted && $can,
            'created_at' => $bill->created_at->toIso8601ZuluString(),
            'updated_at' => $bill->updated_at->toIso8601ZuluString(),
        ];
    }
}
