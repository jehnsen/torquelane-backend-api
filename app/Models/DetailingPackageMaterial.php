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
 * One line of a package's bill of materials: a stocked consumable and what a
 * medium vehicle uses of it. Taken from the shelf when the job completes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $package_id
 * @property string $item_id
 * @property BigDecimal $quantity
 * @property bool $scales_with_size
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Item $item
 */
final class DetailingPackageMaterial extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['scales_with_size' => true];

    protected function casts(): array
    {
        return ['quantity' => DecimalCast::class, 'scales_with_size' => 'boolean'];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
