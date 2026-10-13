<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A credit note against one issued invoice, numbered from the `credit_note`
 * series (`CN-…`) when issued. Immutable (R7): voided once, never edited.
 * Its total comes off the invoice's balance as far as the invoice still owed
 * (`applied_cents`); the rest is the customer's credit (`credit_cents`),
 * which a refund pays out.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $customer_account_id
 * @property string $invoice_id
 * @property string $number
 * @property string $status
 * @property CarbonImmutable $issued_on
 * @property string $reason
 * @property bool $seller_vat_registered
 * @property bool $prices_include_vat
 * @property BigDecimal $vat_rate_pct
 * @property int $vatable_sales_cents
 * @property int $vat_exempt_sales_cents
 * @property int $zero_rated_sales_cents
 * @property int $non_vat_sales_cents
 * @property int $vat_amount_cents
 * @property int $total_cents
 * @property int $applied_cents
 * @property int $credit_cents
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, CreditNoteLine> $lines
 * @property-read Collection<int, Refund> $refunds
 * @property-read Invoice $invoice
 * @property-read CustomerAccount $customerAccount
 */
final class CreditNote extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const string ISSUED = 'issued';

    public const string VOID = 'void';

    protected $attributes = ['status' => self::ISSUED];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'seller_vat_registered' => 'boolean',
            'prices_include_vat' => 'boolean',
            'vat_rate_pct' => DecimalCast::class,
            'vatable_sales_cents' => 'integer',
            'vat_exempt_sales_cents' => 'integer',
            'zero_rated_sales_cents' => 'integer',
            'non_vat_sales_cents' => 'integer',
            'vat_amount_cents' => 'integer',
            'total_cents' => 'integer',
            'applied_cents' => 'integer',
            'credit_cents' => 'integer',
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
        return $this->status === self::ISSUED;
    }

    /** What was credited before VAT. */
    public function netCents(): int
    {
        return $this->total_cents - $this->vat_amount_cents;
    }

    /** Refunded so far out of its credit (relation loaded). */
    public function refundedCents(): int
    {
        return array_sum($this->refunds->filter(fn (Refund $refund): bool => $refund->isStanding())->map(fn (Refund $refund): int => $refund->amount_cents)->all());
    }

    /** The credit it still holds for the customer. A void note holds none. */
    public function refundableCents(): int
    {
        return $this->isStanding() ? $this->credit_cents - $this->refundedCents() : 0;
    }

    /**
     * @return HasMany<CreditNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
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
