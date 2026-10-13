<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Some of a vendor payment applied to one bill of the same vendor.
 * Append-only: voiding the payment takes its allocations out of every bill's
 * paid figure; the rows stay.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $vendor_payment_id
 * @property string $vendor_bill_id
 * @property string $vendor_id
 * @property int $amount_cents
 * @property CarbonImmutable $created_at
 * @property-read VendorPayment $payment
 * @property-read VendorBill $bill
 */
final class VendorPaymentAllocation extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<VendorPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class, 'vendor_payment_id');
    }

    /**
     * @return BelongsTo<VendorBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }
}
