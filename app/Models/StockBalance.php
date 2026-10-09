<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Inventory\StockState;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one item stands at in one location. Written ONLY by
 * App\Actions\Inventory\PostStockMove, in the transaction that appends the
 * move, with this row locked (a trigger refuses any other writer).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $item_id
 * @property BigDecimal $on_hand
 * @property int $avg_cost_cents
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Item $item
 * @property-read StockLocation $location
 */
final class StockBalance extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected function casts(): array
    {
        return [
            'on_hand' => DecimalCast::class,
            'avg_cost_cents' => 'integer',
        ];
    }

    public function state(): StockState
    {
        return new StockState($this->on_hand, $this->avg_cost_cents);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }
}
