<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Receivables\PaymentMethod;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given back to a customer, numbered from the `refund` series (`RF-…`).
 * It pays out credit the customer already holds: what a payment did not
 * allocate, or what a credit note left over. Never edited or deleted, only
 * voided, once.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $customer_account_id
 * @property string $number
 * @property PaymentStatus $status
 * @property PaymentMethod $method
 * @property string|null $reference_no
 * @property int $amount_cents
 * @property CarbonImmutable $refunded_on
 * @property string|null $payment_id
 * @property string|null $credit_note_id
 * @property string $reason
 * @property string|null $refunded_by
 * @property string $refunded_by_name
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Payment|null $payment
 * @property-read CreditNote|null $creditNote
 * @property-read CustomerAccount $customerAccount
 */
final class Refund extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'posted'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'amount_cents' => 'integer',
            'refunded_on' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * Staff: the branches they may see. Portal: their own account's.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        if ($context->isPortal()) {
            $query->where($this->qualifyColumn('customer_account_id'), $context->customerAccountId());

            return;
        }

        $query->whereIn($this->qualifyColumn('branch_id'), $context->allowedBranchIds);
    }

    public function isStanding(): bool
    {
        return $this->status === PaymentStatus::Posted;
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<CreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
