<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A work order carried by an invoice. At most one STANDING link per order
 * (partial unique index): a work order is invoiced once. Voiding the invoice
 * stamps `released_at`, and the order may be invoiced again.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property string $customer_account_id
 * @property string $work_order_id
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable $created_at
 * @property-read Invoice $invoice
 * @property-read WorkOrder $workOrder
 */
final class InvoiceWorkOrder extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
