<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Invoicing\VatTreatment;
use App\Domain\Payables\BillStatus;
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
 * What a vendor billed one branch, numbered from the `vendor_bill` series
 * (`VB-…`) when recorded. A posted document (R7): only its payment status and
 * the void stamp move (trigger). `payable_cents` is the total less what was
 * withheld at source; `paid_cents` is what its standing payments allocate.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $vendor_id
 * @property string $number
 * @property BillStatus $status
 * @property string $vendor_invoice_no
 * @property CarbonImmutable $bill_date
 * @property CarbonImmutable $due_date
 * @property string $vendor_name
 * @property string|null $vendor_tin
 * @property string|null $vendor_address
 * @property bool $vendor_vat_registered
 * @property bool $prices_include_vat
 * @property BigDecimal $vat_rate_pct
 * @property int $vatable_purchases_cents
 * @property int $vat_exempt_purchases_cents
 * @property int $zero_rated_purchases_cents
 * @property int $non_vat_purchases_cents
 * @property int $input_vat_cents
 * @property int $total_cents
 * @property string|null $atc_code
 * @property BigDecimal|null $withholding_rate_pct
 * @property int $withholding_base_cents
 * @property int $withholding_cents
 * @property int $payable_cents
 * @property int $paid_cents
 * @property string $notes
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, VendorBillLine> $lines
 * @property-read Collection<int, VendorPaymentAllocation> $allocations
 * @property-read Vendor $vendor
 * @property-read Branch $branch
 */
final class VendorBill extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'posted', 'notes' => '', 'paid_cents' => 0, 'withholding_cents' => 0, 'withholding_base_cents' => 0];

    protected function casts(): array
    {
        return [
            'status' => BillStatus::class,
            'bill_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'vendor_vat_registered' => 'boolean',
            'prices_include_vat' => 'boolean',
            'vat_rate_pct' => DecimalCast::class,
            'vatable_purchases_cents' => 'integer',
            'vat_exempt_purchases_cents' => 'integer',
            'zero_rated_purchases_cents' => 'integer',
            'non_vat_purchases_cents' => 'integer',
            'input_vat_cents' => 'integer',
            'total_cents' => 'integer',
            'withholding_rate_pct' => DecimalCast::class,
            'withholding_base_cents' => 'integer',
            'withholding_cents' => 'integer',
            'payable_cents' => 'integer',
            'paid_cents' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * Staff: the branches they may see. Payables have no portal side.
     *
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

    public function balanceCents(): int
    {
        return $this->status->isOpen() ? $this->payable_cents - $this->paid_cents : 0;
    }

    public function netPurchasesCents(): int
    {
        return $this->vatable_purchases_cents + $this->vat_exempt_purchases_cents + $this->zero_rated_purchases_cents + $this->non_vat_purchases_cents;
    }

    public function vat(): VatTreatment
    {
        return new VatTreatment($this->vendor_vat_registered, $this->prices_include_vat, (string) $this->vat_rate_pct);
    }

    /**
     * @return HasMany<VendorBillLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(VendorBillLine::class)->orderBy('position')->orderBy('id');
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

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
