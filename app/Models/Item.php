<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Inventory\ItemType;
use App\Domain\Inventory\TaxClass;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item in the shop's own inventory (parts, consumables, retail goods,
 * ingredients, fees), organization-wide. Staff only. Never deleted once used:
 * deactivate it.
 *
 * `uom` is the stock unit; it is bought in `purchase_uom`, each holding
 * `purchase_uom_factor` stock units.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $sku
 * @property string|null $barcode
 * @property string $name
 * @property ItemType $item_type
 * @property string $category
 * @property string $uom
 * @property string|null $purchase_uom
 * @property BigDecimal $purchase_uom_factor
 * @property TaxClass $tax_class
 * @property int $default_price_cents
 * @property bool $is_stocked
 * @property bool $is_active
 * @property string|null $preferred_vendor_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ItemBranchSetting> $branchSettings
 * @property-read Collection<int, StockBalance> $balances
 * @property-read Vendor|null $preferredVendor
 */
final class Item extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['category' => '', 'tax_class' => 'vatable', 'default_price_cents' => 0, 'is_stocked' => true, 'is_active' => true, 'purchase_uom_factor' => '1.000'];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'tax_class' => TaxClass::class,
            'purchase_uom_factor' => DecimalCast::class,
            'default_price_cents' => 'integer',
            'is_stocked' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Staff see every item of the organization; portal users none.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        if (! $context->isStaff()) {
            $query->whereRaw('false');
        }
    }

    /**
     * @return HasMany<ItemBranchSetting, $this>
     */
    public function branchSettings(): HasMany
    {
        return $this->hasMany(ItemBranchSetting::class);
    }

    /**
     * @return HasMany<StockBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function preferredVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'preferred_vendor_id');
    }
}
