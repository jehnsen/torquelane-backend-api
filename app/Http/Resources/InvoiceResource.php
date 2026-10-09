<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Access\Capability;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Receivables\Aging;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceWorkOrder;
use App\Models\PaymentAllocation;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invoice with its lines, the jobs it carries and the payments applied to
 * it. Money in centavos; every figure is the server's (a draft's totals are
 * recomputed on each edit and again at issue). `can_*` say what the caller
 * may do next. The shop's own ids (branch, items) only reach staff.
 *
 * @property Invoice $resource
 */
final class InvoiceResource extends JsonResource
{
    public function __construct(Invoice $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;
        $invoice->loadMissing(['lines', 'workOrderLinks.workOrder', 'allocations.payment', 'customerAccount']);
        $context = app(TenantManager::class)->context();
        $staff = $context?->isStaff() ?? false;
        $can = fn (Capability $capability): bool => $staff && $context->can($capability);
        $today = Calendar::toDate(CarbonImmutable::now());
        $overdue = $invoice->status->isOpen() && $invoice->due_date !== null && $invoice->due_date->toDateString() < $today
            ? Aging::daysPastDue($invoice->due_date->toDateString(), $today)
            : 0;

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'source' => $invoice->source->value,
            'branch_id' => $staff ? $invoice->branch_id : null,
            'customer_account_id' => $invoice->customer_account_id,
            'customer_name' => $invoice->customerAccount->display_name,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days,
            'days_overdue' => $overdue,
            'buyer' => [
                'name' => $invoice->buyer_name,
                'tin' => $invoice->buyer_tin,
                'address' => $invoice->buyer_address,
            ],
            'seller' => [
                'name' => $invoice->seller_name,
                'business_style' => $invoice->seller_business_style,
                'tin' => $invoice->seller_tin,
                'branch_code' => $invoice->seller_branch_code,
                'address' => $invoice->seller_address,
                'vat_registered' => $invoice->seller_vat_registered,
                'header' => $invoice->seller_header,
                'footer' => $invoice->seller_footer,
            ],
            'prices_include_vat' => $invoice->prices_include_vat,
            'vat_rate_pct' => (string) $invoice->vat_rate_pct->strippedOfTrailingZeros(),
            // The wording a non-VAT branch's invoice must carry; null for a VAT-registered branch.
            'non_vat_notice' => $invoice->seller_vat_registered ? null : Invoicing::NON_VAT_NOTICE,
            'totals' => $invoice->totals()->toArray() + ['net_sales_cents' => $invoice->totals()->netSalesCents()],
            'paid_cents' => $invoice->paid_cents,
            'balance_cents' => $invoice->balanceCents(),
            'notes' => $invoice->notes,
            'lines' => array_values($invoice->lines->map(fn (InvoiceLine $line): array => [
                'id' => $line->id,
                'position' => $line->position,
                'kind' => $line->kind->value,
                'description' => $line->description,
                'work_order_id' => $line->work_order_id,
                'work_order_line_id' => $line->work_order_line_id,
                'item_id' => $staff ? $line->item_id : null,
                'service_task_id' => $line->service_task_id,
                'quantity' => Invoicing::quantity((string) $line->quantity),
                'unit_price_cents' => $line->unit_price_cents,
                'discount_cents' => $line->discount_cents,
                'tax_class' => $line->tax_class->value,
                'line_total_cents' => $line->line_total_cents,
            ])->all()),
            'work_orders' => array_values($invoice->workOrderLinks->map(fn (InvoiceWorkOrder $link): array => [
                'id' => $link->work_order_id,
                'reference' => $link->workOrder->reference,
                'title' => $link->workOrder->title,
                'completed_on' => $link->workOrder->completed_on?->toDateString(),
                'released' => $link->released_at !== null,
            ])->all()),
            'payments' => array_values($invoice->allocations->map(fn (PaymentAllocation $allocation): array => [
                'allocation_id' => $allocation->id,
                'payment_id' => $allocation->payment_id,
                'number' => $allocation->payment->number,
                'method' => $allocation->payment->method->value,
                'reference_no' => $allocation->payment->reference_no,
                'amount_cents' => $allocation->amount_cents,
                'allocated_on' => $allocation->allocated_on->toDateString(),
                'payment_status' => $allocation->payment->status->value,
                'counts' => $allocation->payment->status === PaymentStatus::Posted,
            ])->all()),
            'created_by_name' => $invoice->created_by_name,
            'issued_at' => $invoice->issued_at?->toIso8601ZuluString(),
            'issued_by_name' => $invoice->issued_by_name,
            'voided_at' => $invoice->voided_at?->toIso8601ZuluString(),
            'voided_by_name' => $invoice->voided_by_name,
            'void_reason' => $invoice->void_reason,
            'can_edit' => $invoice->status === InvoiceStatus::Draft && $can(Capability::BillingManage),
            'can_issue' => $invoice->status === InvoiceStatus::Draft && $can(Capability::BillingManage),
            'can_void' => $invoice->status === InvoiceStatus::Issued && $can(Capability::BillingVoid),
            'can_record_payment' => $invoice->status->isOpen() && $can(Capability::BillingManage),
            'created_at' => $invoice->created_at->toIso8601ZuluString(),
            'updated_at' => $invoice->updated_at->toIso8601ZuluString(),
        ];
    }
}
