<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\ShopOrderStatus;
use App\Domain\Inventory\StoredOrderStatus;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase order the SHOP raises to a vendor for one branch's stock (or one
 * job). Not Phase 4's `purchase_orders`, which are a customer account's own
 * spare parts. Only its draft/issued/cancelled decision is stored; whether it
 * is partially or fully received follows from the goods receipts
 * (`derivedStatus()`). Once issued only that decision moves (triggers), and it is
 * never deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $vendor_id
 * @property string $vendor_name
 * @property string $reference
 * @property StoredOrderStatus $status
 * @property CarbonImmutable $created_on
 * @property CarbonImmutable|null $expected_on
 * @property string|null $created_by
 * @property string $created_by_name
 * @property string $notes
 * @property int $total_cents
 * @property CarbonImmutable|null $issued_at
 * @property string|null $issued_by_name
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by_name
 * @property string|null $cancellation_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ShopPurchaseOrderLine> $lines
 * @property-read Collection<int, ShopPurchaseOrderEvent> $events
 * @property-read Collection<int, GoodsReceipt> $receipts
 * @property-read Branch $branch
 */
final class ShopPurchaseOrder extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $attributes = ['status' => 'draft', 'notes' => '', 'total_cents' => 0];

    protected function casts(): array
    {
        return [
            'status' => StoredOrderStatus::class,
            'created_on' => 'immutable_date',
            'expected_on' => 'immutable_date',
            'total_cents' => 'integer',
            'issued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Ordered vs received per line, in the purchase unit. Receipts that were voided do not count.
     *
     * @param  array<string, BigDecimal>  $receivedByLine  line id → quantity received on posted receipts
     * @return list<array{ordered: string, received: string}>
     */
    public function quantities(array $receivedByLine): array
    {
        return array_values($this->lines->map(fn (ShopPurchaseOrderLine $line): array => [
            'ordered' => (string) $line->quantity,
            'received' => (string) ($receivedByLine[$line->id] ?? BigDecimal::zero()),
        ])->all());
    }

    /**
     * @param  array<string, BigDecimal>  $receivedByLine
     */
    public function derivedStatus(array $receivedByLine): ShopOrderStatus
    {
        return ShopOrderStatus::derive($this->status->value, $this->quantities($receivedByLine));
    }

    /**
     * @return HasMany<ShopPurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShopPurchaseOrderLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ShopPurchaseOrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShopPurchaseOrderEvent::class)->orderBy('at')->orderBy('id');
    }

    /**
     * @return HasMany<GoodsReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
