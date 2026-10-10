<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Detailing\SizeClass;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An extra a booking may carry (the source's `addon_catalog`), priced per size class.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, DetailingAddonPrice> $prices
 */
final class DetailingAddon extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $attributes = ['is_active' => true, 'position' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    /**
     * @return HasMany<DetailingAddonPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(DetailingAddonPrice::class, 'addon_id');
    }

    public function priceFor(SizeClass $size): ?DetailingAddonPrice
    {
        return $this->prices->first(fn (DetailingAddonPrice $price): bool => $price->size_class === $size);
    }
}
