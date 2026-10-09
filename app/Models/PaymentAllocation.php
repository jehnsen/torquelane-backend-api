<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Some of a payment applied to one invoice of the same account. Append-only:
 * voiding the payment takes all of its allocations out of every invoice's
 * paid figure; the rows stay.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $payment_id
 * @property string $invoice_id
 * @property string $customer_account_id
 * @property int $amount_cents
 * @property CarbonImmutable $allocated_on
 * @property CarbonImmutable $allocated_at
 * @property string|null $allocated_by
 * @property string $allocated_by_name
 * @property CarbonImmutable $created_at
 * @property-read Payment $payment
 * @property-read Invoice $invoice
 */
final class PaymentAllocation extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'allocated_on' => 'immutable_date',
            'allocated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
