<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockSource;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the stock ledger. Append-only (triggers refuse UPDATE and
 * DELETE): a correction is a compensating move. `quantity` is signed;
 * `unit_cost_cents` is what the goods cost per stock unit when the move
 * happened (outbound moves leave at the average).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $item_id
 * @property BigDecimal $quantity
 * @property int $unit_cost_cents
 * @property MoveType $move_type
 * @property StockSource $source_type
 * @property string|null $source_id
 * @property CarbonImmutable $occurred_at
 * @property string|null $actor_id
 * @property string $actor_name
 * @property string|null $reason
 * @property bool $negative_flag
 * @property CarbonImmutable $created_at
 * @property-read Item $item
 */
final class StockMove extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity' => DecimalCast::class,
            'unit_cost_cents' => 'integer',
            'move_type' => MoveType::class,
            'source_type' => StockSource::class,
            'occurred_at' => 'immutable_datetime',
            'negative_flag' => 'boolean',
        ];
    }

    /** What the move was worth: |quantity| × the unit cost it was recorded at. */
    public function valueCents(): int
    {
        return StockLedger::moveValue($this->quantity, $this->unit_cost_cents);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
