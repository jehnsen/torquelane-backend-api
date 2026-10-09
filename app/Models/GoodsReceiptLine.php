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
 * What one purchase order line took in on one receipt. Append-only.
 * `quantity` / `unit_cost_cents` are in the purchase unit as ordered;
 * `stock_quantity` / `stock_unit_cost_cents` are what went on the shelf.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $goods_receipt_id
 * @property string $shop_purchase_order_line_id
 * @property int $position
 * @property string|null $item_id
 * @property BigDecimal $quantity
 * @property int $unit_cost_cents
 * @property int $line_total_cents
 * @property BigDecimal $stock_quantity
 * @property int $stock_unit_cost_cents
 * @property CarbonImmutable $created_at
 * @property-read Item|null $item
 * @property-read ShopPurchaseOrderLine $orderLine
 */
final class GoodsReceiptLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => DecimalCast::class,
            'unit_cost_cents' => 'integer',
            'line_total_cents' => 'integer',
            'stock_quantity' => DecimalCast::class,
            'stock_unit_cost_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ShopPurchaseOrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(ShopPurchaseOrderLine::class, 'shop_purchase_order_line_id');
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }
}
