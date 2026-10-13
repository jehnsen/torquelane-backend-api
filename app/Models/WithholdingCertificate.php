<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A BIR 2307 a customer handed over: tax they withheld at source from what
 * an invoice was owed. Its amount comes off the invoice's balance and becomes
 * a tax credit of the shop's. Posted, then voided at most once.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $customer_account_id
 * @property string $invoice_id
 * @property PaymentStatus $status
 * @property string|null $certificate_no
 * @property string $atc_code
 * @property BigDecimal $rate_pct
 * @property int $tax_base_cents
 * @property int $amount_cents
 * @property CarbonImmutable $received_on
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $notes
 * @property string|null $recorded_by
 * @property string $recorded_by_name
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Invoice $invoice
 * @property-read CustomerAccount $customerAccount
 */
final class WithholdingCertificate extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'posted', 'notes' => ''];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'rate_pct' => DecimalCast::class,
            'tax_base_cents' => 'integer',
            'amount_cents' => 'integer',
            'received_on' => 'immutable_date',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
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
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
