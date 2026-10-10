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
 * A part a service entry took from stock: the item, how much, what it was
 * worth when it left the shelf, and the stock move that took it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $maintenance_log_id
 * @property string $item_id
 * @property BigDecimal $quantity
 * @property int $unit_cost_cents
 * @property int $value_cents
 * @property string $stock_move_id
 * @property CarbonImmutable $created_at
 * @property-read Item $item
 */
final class EquipmentMaintenancePart extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity' => DecimalCast::class,
            'unit_cost_cents' => 'integer',
            'value_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
