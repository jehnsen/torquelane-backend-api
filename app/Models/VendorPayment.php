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
 * Money paid to a vendor from a branch: the cash-out voucher, numbered from
 * the `vendor_payment` series (`PV-…`). Posted with its allocations to that
 * vendor's bills, which add up to it exactly; never edited or deleted, only
 * voided, once.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $vendor_id
 * @property string $number
 * @property PaymentStatus $status
 * @property PaymentMethod $method
 * @property string|null $reference_no
 * @property int $amount_cents
 * @property CarbonImmutable $paid_on
 * @property string|null $paid_by
 * @property string $paid_by_name
 * @property string $notes
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, VendorPaymentAllocation> $allocations
 * @property-read Vendor $vendor
 */
final class VendorPayment extends Model
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
            'paid_on' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        if ($context->isPortal()) {
            $query->whereRaw('false');

            return;
        }

        $query->whereIn($this->qualifyColumn('branch_id'), $context->allowedBranchIds);
    }

    /**
     * @return HasMany<VendorPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
