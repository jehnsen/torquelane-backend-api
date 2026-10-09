<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a shop purchase order, in the PURCHASE unit and costed per
 * purchase unit. A line buys a stocked item, or is bought for a job (and
 * names the work-order line it serves).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $shop_purchase_order_id
 * @property int $position
 * @property string|null $item_id
 * @property string $description
 * @property BigDecimal $quantity
 * @property int $unit_cost_cents
 * @property int $line_total_cents
 * @property string|null $work_order_line_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Item|null $item
 */
final class ShopPurchaseOrderLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => DecimalCast::class,
            'unit_cost_cents' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ShopPurchaseOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopPurchaseOrder::class, 'shop_purchase_order_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
