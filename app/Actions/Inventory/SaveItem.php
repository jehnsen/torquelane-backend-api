<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Audit\AuditTrail;
use App\Domain\Inventory\Decimals;
use App\Domain\Inventory\ItemType;
use App\Models\Item;
use App\Models\ItemBranchSetting;
use App\Models\StockMove;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Items and their per-branch settings. An item is never deleted (stock moves
 * and work orders name it): deactivate it. Once stock has moved, the stock
 * unit and whether it is stocked are fixed, since the balances are in that
 * unit.
 */
final class SaveItem
{
    /** Columns a caller may set. */
    public const array FIELDS = [
        'sku', 'barcode', 'name', 'item_type', 'category', 'uom', 'purchase_uom', 'purchase_uom_factor',
        'tax_class', 'default_price_cents', 'is_stocked', 'is_active', 'preferred_vendor_id',
    ];

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated
     */
    public function create(array $attributes): Item
    {
        return DB::transaction(function () use ($attributes): Item {
            $attributes = $this->normalise($attributes, null);
            $this->assertUnique($attributes, null);
            $this->assertVendor($attributes);

            $item = new Item;
            $item->forceFill($attributes)->save();
            $this->audit->record($item, 'created', null, AuditTrail::snapshot($item));

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  validated, only the fields to change
     */
    public function update(Item $item, array $attributes): Item
    {
        return DB::transaction(function () use ($item, $attributes): Item {
            $locked = Item::query()->lockForUpdate()->findOrFail($item->id);
            $attributes = $this->normalise($attributes, $locked);
            $this->assertUnique($attributes, $locked);
            $this->assertVendor($attributes);
            $this->assertStockUnitsFixed($locked, $attributes);

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * Set (or clear, with nulls) an item's settings for one branch. The branch
     * must be reachable by the caller: the policy checked it.
     *
     * @param  array{reorder_point?: string|null, reorder_qty?: string|null, bin?: string|null, price_override_cents?: int|null}  $attributes
     */
    public function setBranchSettings(Item $item, string $branchId, array $attributes): ItemBranchSetting
    {
        return DB::transaction(function () use ($item, $branchId, $attributes): ItemBranchSetting {
            $locked = Item::query()->lockForUpdate()->findOrFail($item->id);
            $setting = ItemBranchSetting::query()->where('item_id', $locked->id)->where('branch_id', $branchId)->first() ?? new ItemBranchSetting;
            $before = $setting->exists ? AuditTrail::snapshot($setting) : null;

            $setting->forceFill(['item_id' => $locked->id, 'branch_id' => $branchId, ...$attributes])->save();
            $this->audit->record($setting, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($setting));

            return $setting;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(array $attributes, ?Item $existing): array
    {
        $attributes = array_intersect_key($attributes, array_flip(self::FIELDS));
        foreach (['sku', 'barcode', 'name', 'uom', 'purchase_uom', 'category'] as $text) {
            if (isset($attributes[$text]) && is_string($attributes[$text])) {
                $attributes[$text] = trim($attributes[$text]);
            }
        }
        if (array_key_exists('barcode', $attributes) && $attributes['barcode'] === '') {
            $attributes['barcode'] = null;
        }
        if (array_key_exists('purchase_uom', $attributes) && $attributes['purchase_uom'] === null) {
            // No purchase unit: stock units are bought as they are.
            $attributes['purchase_uom_factor'] = '1';
        }
        $unit = array_key_exists('purchase_uom', $attributes) ? $attributes['purchase_uom'] : $existing?->purchase_uom;
        $factor = $attributes['purchase_uom_factor'] ?? $existing?->purchase_uom_factor;
        if ($unit === null && ! Decimals::of($factor ?? 1)->isEqualTo(1)) {
            throw ValidationException::withMessages(['purchase_uom_factor' => 'Name the purchase unit this factor is for.']);
        }
        $type = $attributes['item_type'] ?? $existing?->item_type;
        $type = $type instanceof ItemType ? $type : (is_string($type) ? ItemType::from($type) : null);
        if ($type === ItemType::ServiceFee) {
            $attributes['is_stocked'] = false;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertUnique(array $attributes, ?Item $except): void
    {
        $errors = [];
        if (isset($attributes['sku']) && is_string($attributes['sku'])
            && Item::query()->whereRaw('lower(sku) = ?', [mb_strtolower($attributes['sku'])])->when($except !== null, fn ($q) => $q->whereKeyNot($except?->id))->exists()) {
            $errors['sku'] = 'Another item already uses that SKU.';
        }
        if (isset($attributes['barcode']) && is_string($attributes['barcode'])
            && Item::query()->whereRaw('lower(barcode) = ?', [mb_strtolower($attributes['barcode'])])->when($except !== null, fn ($q) => $q->whereKeyNot($except?->id))->exists()) {
            $errors['barcode'] = 'Another item already uses that barcode.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertVendor(array $attributes): void
    {
        if (isset($attributes['preferred_vendor_id']) && is_string($attributes['preferred_vendor_id'])
            && ! Vendor::query()->whereKey($attributes['preferred_vendor_id'])->exists()) {
            throw ValidationException::withMessages(['preferred_vendor_id' => 'That vendor is not on your list.']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertStockUnitsFixed(Item $item, array $attributes): void
    {
        $changesUnit = (isset($attributes['uom']) && $attributes['uom'] !== $item->uom)
            || (isset($attributes['is_stocked']) && (bool) $attributes['is_stocked'] !== $item->is_stocked);
        if ($changesUnit && StockMove::query()->where('item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['uom' => 'Stock has moved in this item\'s unit; it cannot change its unit or stocked status. Create a new item instead.']);
        }
    }
}
