<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Access\Capability;
use App\Domain\Invoicing\Invoicing;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Refund;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A credit note: what it credits, how much of it came off the invoice
 * (`applied_cents`) and how much became the customer's credit
 * (`credit_cents`), and what of that credit is still to be refunded
 * (`refundable_cents`). Money in centavos.
 *
 * @property CreditNote $resource
 */
final class CreditNoteResource extends JsonResource
{
    public function __construct(CreditNote $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $note = $this->resource;
        $note->loadMissing(['lines', 'refunds', 'invoice', 'customerAccount']);
        $context = app(TenantManager::class)->context();
        $staff = $context?->isStaff() ?? false;
        $can = $staff && $context->can(Capability::BillingVoid);

        return [
            'id' => $note->id,
            'number' => $note->number,
            'status' => $note->status,
            'issued_on' => $note->issued_on->toDateString(),
            'reason' => $note->reason,
            'branch_id' => $staff ? $note->branch_id : null,
            'customer_account_id' => $note->customer_account_id,
            'customer_name' => $note->customerAccount->display_name,
            'invoice_id' => $note->invoice_id,
            'invoice_number' => $note->invoice->number,
            'prices_include_vat' => $note->prices_include_vat,
            'vat_rate_pct' => (string) $note->vat_rate_pct->strippedOfTrailingZeros(),
            'totals' => [
                'vatable_sales_cents' => $note->vatable_sales_cents,
                'vat_exempt_sales_cents' => $note->vat_exempt_sales_cents,
                'zero_rated_sales_cents' => $note->zero_rated_sales_cents,
                'non_vat_sales_cents' => $note->non_vat_sales_cents,
                'net_sales_cents' => $note->netCents(),
                'vat_amount_cents' => $note->vat_amount_cents,
                'total_cents' => $note->total_cents,
            ],
            'applied_cents' => $note->applied_cents,
            'credit_cents' => $note->credit_cents,
            'refunded_cents' => $note->refundedCents(),
            'refundable_cents' => $note->refundableCents(),
            'lines' => array_values($note->lines->map(fn (CreditNoteLine $line): array => [
                'id' => $line->id,
                'position' => $line->position,
                'invoice_line_id' => $line->invoice_line_id,
                'description' => $line->description,
                'quantity' => Invoicing::quantity((string) $line->quantity),
                'unit_price_cents' => $line->unit_price_cents,
                'tax_class' => $line->tax_class->value,
                'line_total_cents' => $line->line_total_cents,
            ])->all()),
            'refunds' => array_values($note->refunds->map(fn (Refund $refund): array => [
                'id' => $refund->id,
                'number' => $refund->number,
                'amount_cents' => $refund->amount_cents,
                'refunded_on' => $refund->refunded_on->toDateString(),
                'status' => $refund->status->value,
                'counts' => $refund->isStanding(),
            ])->all()),
            'created_by_name' => $note->created_by_name,
            'voided_at' => $note->voided_at?->toIso8601ZuluString(),
            'voided_by_name' => $note->voided_by_name,
            'void_reason' => $note->void_reason,
            'can_refund' => $note->refundableCents() > 0 && $can,
            'can_void' => $note->isStanding() && $note->refundedCents() === 0 && $can,
            'created_at' => $note->created_at->toIso8601ZuluString(),
        ];
    }
}
