<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Receivables\PaymentMethod;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a customer account at a branch, numbered from the
 * `payment` series (`PAY-…`). An issued document (R7): never edited or
 * deleted, only voided, once. What its allocations do not cover is the
 * customer's credit.
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
 * @property CarbonImmutable $received_on
 * @property string|null $received_by
 * @property string $received_by_name
 * @property string $notes
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, PaymentAllocation> $allocations
 * @property-read CustomerAccount $customerAccount
 * @property-read Branch $branch
 */
final class Payment extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'posted', 'notes' => ''];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'amount_cents' => 'integer',
            'received_on' => 'immutable_date',
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

    /** Allocated so far (relation loaded). */
    public function allocatedCents(): int
    {
        return array_sum($this->allocations->map(fn (PaymentAllocation $allocation): int => $allocation->amount_cents)->all());
    }

    /** What is left to apply: the customer's credit from this payment. A void payment has none. */
    public function unallocatedCents(): int
    {
        return $this->status === PaymentStatus::Posted ? $this->amount_cents - $this->allocatedCents() : 0;
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('allocated_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
