<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Invoicing\InvoiceSource;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\InvoiceTotals;
use App\Domain\Invoicing\VatTreatment;
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
 * An invoice to one customer account from one branch. Unnumbered while a
 * draft; numbered from the `invoice` series when issued, and from then on an
 * issued financial document (R7): only its payment status and the void stamp
 * move (triggers). Its status between issue and void follows the allocations
 * of the payments that still stand (`paid_cents`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $customer_account_id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property InvoiceSource $source
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $due_date
 * @property int $payment_terms_days
 * @property string $buyer_name
 * @property string|null $buyer_tin
 * @property string|null $buyer_address
 * @property string $seller_name
 * @property string|null $seller_business_style
 * @property string|null $seller_tin
 * @property string|null $seller_branch_code
 * @property string|null $seller_address
 * @property bool $seller_vat_registered
 * @property string|null $seller_header
 * @property string|null $seller_footer
 * @property bool $prices_include_vat
 * @property BigDecimal $vat_rate_pct
 * @property int $vatable_sales_cents
 * @property int $vat_exempt_sales_cents
 * @property int $zero_rated_sales_cents
 * @property int $non_vat_sales_cents
 * @property int $discount_total_cents
 * @property int $vat_amount_cents
 * @property int $total_due_cents
 * @property int $paid_cents
 * @property string $notes
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable|null $issued_at
 * @property string|null $issued_by_name
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, InvoiceWorkOrder> $workOrderLinks
 * @property-read Collection<int, PaymentAllocation> $allocations
 * @property-read CustomerAccount $customerAccount
 * @property-read Branch $branch
 */
final class Invoice extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = [
        'status' => 'draft',
        'notes' => '',
        'payment_terms_days' => 0,
        'vatable_sales_cents' => 0,
        'vat_exempt_sales_cents' => 0,
        'zero_rated_sales_cents' => 0,
        'non_vat_sales_cents' => 0,
        'discount_total_cents' => 0,
        'vat_amount_cents' => 0,
        'total_due_cents' => 0,
        'paid_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'source' => InvoiceSource::class,
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'payment_terms_days' => 'integer',
            'seller_vat_registered' => 'boolean',
            'prices_include_vat' => 'boolean',
            'vat_rate_pct' => DecimalCast::class,
            'vatable_sales_cents' => 'integer',
            'vat_exempt_sales_cents' => 'integer',
            'zero_rated_sales_cents' => 'integer',
            'non_vat_sales_cents' => 'integer',
            'discount_total_cents' => 'integer',
            'vat_amount_cents' => 'integer',
            'total_due_cents' => 'integer',
            'paid_cents' => 'integer',
            'issued_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * Staff: the branches they may see. Portal: their own account's invoices
     * once issued (a draft is the shop's working paper).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        if ($context->isPortal()) {
            $query->where($this->qualifyColumn('customer_account_id'), $context->customerAccountId())
                ->where($this->qualifyColumn('status'), '<>', InvoiceStatus::Draft->value);

            return;
        }

        $query->whereIn($this->qualifyColumn('branch_id'), $context->allowedBranchIds);
    }

    public function balanceCents(): int
    {
        return $this->status->isOpen() ? $this->total_due_cents - $this->paid_cents : 0;
    }

    public function vat(): VatTreatment
    {
        return new VatTreatment($this->seller_vat_registered, $this->prices_include_vat, (string) $this->vat_rate_pct);
    }

    public function totals(): InvoiceTotals
    {
        return new InvoiceTotals(
            $this->vatable_sales_cents,
            $this->vat_exempt_sales_cents,
            $this->zero_rated_sales_cents,
            $this->non_vat_sales_cents,
            $this->discount_total_cents,
            $this->vat_amount_cents,
            $this->total_due_cents,
        );
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Every order this invoice has carried; `released_at` set once it was voided.
     *
     * @return HasMany<InvoiceWorkOrder, $this>
     */
    public function workOrderLinks(): HasMany
    {
        return $this->hasMany(InvoiceWorkOrder::class)->orderBy('created_at')->orderBy('id');
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
