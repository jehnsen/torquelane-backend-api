<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Access\Capability;
use App\Models\Refund;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A refund and the credit it paid out: a payment's unallocated money, or a
 * credit note's. Money in centavos.
 *
 * @property Refund $resource
 */
final class RefundResource extends JsonResource
{
    public function __construct(Refund $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refund = $this->resource;
        $refund->loadMissing(['payment', 'creditNote', 'customerAccount']);
        $context = app(TenantManager::class)->context();
        $staff = $context?->isStaff() ?? false;

        return [
            'id' => $refund->id,
            'number' => $refund->number,
            'status' => $refund->status->value,
            'method' => $refund->method->value,
            'method_label' => $refund->method->label(),
            'reference_no' => $refund->reference_no,
            'amount_cents' => $refund->amount_cents,
            'refunded_on' => $refund->refunded_on->toDateString(),
            'refunded_by_name' => $refund->refunded_by_name,
            'reason' => $refund->reason,
            'branch_id' => $staff ? $refund->branch_id : null,
            'customer_account_id' => $refund->customer_account_id,
            'customer_name' => $refund->customerAccount->display_name,
            'source' => $refund->payment_id !== null ? 'payment' : 'credit_note',
            'payment_id' => $refund->payment_id,
            'payment_number' => $refund->payment?->number,
            'credit_note_id' => $refund->credit_note_id,
            'credit_note_number' => $refund->creditNote?->number,
            'voided_at' => $refund->voided_at?->toIso8601ZuluString(),
            'voided_by_name' => $refund->voided_by_name,
            'void_reason' => $refund->void_reason,
            'can_void' => $refund->isStanding() && $staff && $context->can(Capability::BillingVoid),
            'created_at' => $refund->created_at->toIso8601ZuluString(),
        ];
    }
}
