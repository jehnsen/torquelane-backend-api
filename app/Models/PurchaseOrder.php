<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PurchaseOrders\PurchaseOrderStatus;
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
 * A customer account's purchase order for its own spare parts. Written only
 * by App\Actions\PurchaseOrders; issued orders are immutable but for their
 * status (triggers), and never deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property string $reference
 * @property string $vendor
 * @property PurchaseOrderStatus $status
 * @property CarbonImmutable $created_on
 * @property string|null $created_by
 * @property string $created_by_name
 * @property string $notes
 * @property int $total_cents
 * @property CarbonImmutable|null $sent_at
 * @property string|null $sent_by_name
 * @property CarbonImmutable|null $received_at
 * @property string|null $received_by_name
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by_name
 * @property string|null $cancellation_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, PurchaseOrderLine> $lines
 * @property-read Collection<int, PurchaseOrderEvent> $events
 * @property-read CustomerAccount $customerAccount
 */
final class PurchaseOrder extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'draft', 'notes' => '', 'total_cents' => 0];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'created_on' => 'immutable_date',
            'total_cents' => 'integer',
            'sent_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Portal: their own account's orders. Staff: every account's.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $accountId = $context->customerAccountId();
        if ($accountId !== null) {
            $query->where($this->qualifyColumn('customer_account_id'), $accountId);
        }
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<PurchaseOrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PurchaseOrderEvent::class)->orderBy('at')->orderBy('id');
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
