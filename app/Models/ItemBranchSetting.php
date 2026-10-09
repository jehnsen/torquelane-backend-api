<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item as one branch runs it: when to reorder and how much, where it sits,
 * and the branch's own price if it differs from the item's.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $item_id
 * @property string $branch_id
 * @property BigDecimal|null $reorder_point
 * @property BigDecimal|null $reorder_qty
 * @property string|null $bin
 * @property int|null $price_override_cents
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Item $item
 */
final class ItemBranchSetting extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected function casts(): array
    {
        return [
            'reorder_point' => DecimalCast::class,
            'reorder_qty' => DecimalCast::class,
            'price_override_cents' => 'integer',
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
