<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\StockLedger;
use App\Models\Item;
use App\Models\ItemBranchSetting;
use App\Models\StockBalance;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item with, for every branch the caller may see, how that branch runs it
 * (reorder point and quantity, bin, price override and the price that
 * applies) and what it holds (on hand, average cost, value). `totals` add
 * those branches up. Eager-load `branchSettings` and `balances`.
 *
 * @property Item $resource
 */
final class ItemResource extends JsonResource
{
    public function __construct(Item $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $context = app(TenantManager::class)->context();
        $branchIds = $context === null ? [] : $context->allowedBranchIds;
        $settings = $item->branchSettings->keyBy('branch_id');
        $balances = $item->balances->keyBy('branch_id');

        $totalOnHand = BigDecimal::zero();
        $totalValue = BigDecimal::zero();
        $branches = [];
        foreach ($branchIds as $branchId) {
            $setting = $settings->get($branchId);
            $balance = $balances->get($branchId);
            $onHand = $balance instanceof StockBalance ? $balance->on_hand : BigDecimal::zero();
            $avg = $balance instanceof StockBalance ? $balance->avg_cost_cents : 0;
            $totalOnHand = $totalOnHand->plus($onHand);
            $totalValue = $totalValue->plus($onHand->multipliedBy($avg));

            $branches[] = [
                'branch_id' => $branchId,
                'location_id' => $balance instanceof StockBalance ? $balance->location_id : null,
                'reorder_point' => $setting instanceof ItemBranchSetting ? InventoryJson::quantity($setting->reorder_point) : null,
                'reorder_qty' => $setting instanceof ItemBranchSetting ? InventoryJson::quantity($setting->reorder_qty) : null,
                'bin' => $setting?->bin,
                'price_override_cents' => $setting->price_override_cents ?? null,
                // What a line for this item is priced at in this branch.
                'effective_price_cents' => $setting->price_override_cents ?? $item->default_price_cents,
                'on_hand' => InventoryJson::quantity($onHand),
                'avg_cost_cents' => $avg,
                'value_cents' => InventoryJson::value($onHand, $avg),
            ];
        }

        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'barcode' => $item->barcode,
            'name' => $item->name,
            'item_type' => $item->item_type->value,
            'category' => $item->category,
            'uom' => $item->uom,
            'purchase_uom' => $item->purchase_uom,
            'purchase_uom_factor' => InventoryJson::quantity($item->purchase_uom_factor),
            'tax_class' => $item->tax_class->value,
            'default_price_cents' => $item->default_price_cents,
            'is_stocked' => $item->is_stocked,
            'is_active' => $item->is_active,
            'preferred_vendor_id' => $item->preferred_vendor_id,
            'preferred_vendor_name' => $item->preferredVendor?->name,
            'branches' => $branches,
            'totals' => [
                'on_hand' => InventoryJson::quantity($totalOnHand),
                'value_cents' => StockLedger::roundCents($totalValue),
            ],
            'created_at' => $item->created_at->toIso8601ZuluString(),
            'updated_at' => $item->updated_at->toIso8601ZuluString(),
        ];
    }
}
