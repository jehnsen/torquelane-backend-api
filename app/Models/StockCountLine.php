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
 * One item on a count sheet: what the books held, what was counted, and the
 * variance fixed at posting.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $stock_count_id
 * @property string $item_id
 * @property BigDecimal $expected_quantity
 * @property BigDecimal|null $counted_quantity
 * @property BigDecimal|null $variance_quantity
 * @property int|null $unit_cost_cents
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Item $item
 */
final class StockCountLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'expected_quantity' => DecimalCast::class,
            'counted_quantity' => DecimalCast::class,
            'variance_quantity' => DecimalCast::class,
            'unit_cost_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
