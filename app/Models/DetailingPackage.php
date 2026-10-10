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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A detailing package on a branch's menu (the source's `services`). One row
 * whatever the vehicle's size: `prices` holds a price per size class, where
 * the source kept a row per size with the size in its name.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string|null $category_id
 * @property string $name
 * @property string|null $description
 * @property string $duration_text
 * @property list<string> $inclusions
 * @property bool $is_popular
 * @property int $min_detailers
 * @property int $recommended_detailers
 * @property string $applies_to
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read DetailingCategory|null $category
 * @property-read Collection<int, DetailingPackagePrice> $prices
 * @property-read Collection<int, DetailingPackageMaterial> $materials
 */
final class DetailingPackage extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    public const array APPLIES_TO = ['all', 'four_wheel', 'motorcycle'];

    protected $attributes = [
        'inclusions' => '[]',
        'is_popular' => false,
        'min_detailers' => 1,
        'recommended_detailers' => 1,
        'applies_to' => 'all',
        'is_active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'inclusions' => 'array',
            'is_popular' => 'boolean',
            'min_detailers' => 'integer',
            'recommended_detailers' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DetailingCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DetailingCategory::class, 'category_id');
    }

    /**
     * @return HasMany<DetailingPackagePrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(DetailingPackagePrice::class, 'package_id');
    }

    /**
     * @return HasMany<DetailingPackageMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(DetailingPackageMaterial::class, 'package_id')->orderBy('created_at')->orderBy('id');
    }

    /** The price row of a size, when the package is offered in it. */
    public function priceFor(SizeClass $size): ?DetailingPackagePrice
    {
        return $this->prices->first(fn (DetailingPackagePrice $price): bool => $price->size_class === $size);
    }

    /** How long the package takes at a size: that size's own duration, else the package's. */
    public function durationFor(SizeClass $size): string
    {
        return $this->priceFor($size)->duration_text ?? $this->duration_text;
    }
}
