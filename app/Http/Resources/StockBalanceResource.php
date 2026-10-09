<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Decimals;
use App\Models\StockBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of Stock on hand: an item in a location, what it is worth, and how
 * it stands against its branch's reorder point. `value_cents` is on hand ×
 * average cost, rounded once.
 *
 * @property StockBalance $resource
 */
final class StockBalanceResource extends JsonResource
{
    public function __construct(StockBalance $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $balance = $this->resource;
        $attributes = $balance->getAttributes();
        $point = Decimals::nullable($attributes['reorder_point'] ?? null);
        $reorderQty = Decimals::nullable($attributes['reorder_qty'] ?? null);

        return [
            'id' => $balance->id,
            'item' => InventoryJson::item($balance->item),
            'branch_id' => $balance->branch_id,
            'location_id' => $balance->location_id,
            'location_name' => $balance->location->name,
            'on_hand' => InventoryJson::quantity($balance->on_hand),
            'avg_cost_cents' => $balance->avg_cost_cents,
            'value_cents' => InventoryJson::value($balance->on_hand, $balance->avg_cost_cents),
            'reorder_point' => InventoryJson::quantity($point),
            'reorder_qty' => InventoryJson::quantity($reorderQty),
            'bin' => is_string($attributes['bin'] ?? null) ? $attributes['bin'] : null,
            'is_low' => $point !== null && $balance->on_hand->isLessThanOrEqualTo($point),
            'is_negative' => $balance->on_hand->isNegative(),
            'updated_at' => $balance->updated_at->toIso8601ZuluString(),
        ];
    }
}
