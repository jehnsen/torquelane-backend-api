<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Goods taken in. Quantity and unit cost are in the purchase unit as ordered;
 * `stock_quantity` / `stock_unit_cost_cents` are what went on the shelf.
 * A void receipt stays on record, marked.
 *
 * @property GoodsReceipt $resource
 */
final class GoodsReceiptResource extends JsonResource
{
    public function __construct(GoodsReceipt $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $receipt = $this->resource;
        $receipt->loadMissing(['lines.item', 'lines.orderLine', 'order']);

        return [
            'id' => $receipt->id,
            'reference' => $receipt->reference,
            'branch_id' => $receipt->branch_id,
            'location_id' => $receipt->location_id,
            'shop_purchase_order_id' => $receipt->shop_purchase_order_id,
            'order_reference' => $receipt->order->reference,
            'vendor_name' => $receipt->order->vendor_name,
            'status' => $receipt->status->value,
            'received_on' => $receipt->received_on->toDateString(),
            'supplier_ref' => $receipt->supplier_ref,
            'notes' => $receipt->notes,
            'total_cents' => $receipt->total_cents,
            'received_by_name' => $receipt->received_by_name,
            'voided_at' => $receipt->voided_at?->toIso8601ZuluString(),
            'voided_by_name' => $receipt->voided_by_name,
            'void_reason' => $receipt->void_reason,
            'can_void' => $receipt->status->value === 'posted',
            'lines' => array_values($receipt->lines->map(fn (GoodsReceiptLine $line): array => [
                'id' => $line->id,
                'shop_purchase_order_line_id' => $line->shop_purchase_order_line_id,
                'item' => $line->item === null ? null : InventoryJson::item($line->item),
                'description' => $line->orderLine->description,
                'quantity' => InventoryJson::quantity($line->quantity),
                'unit_cost_cents' => $line->unit_cost_cents,
                'line_total_cents' => $line->line_total_cents,
                'stock_quantity' => InventoryJson::quantity($line->stock_quantity),
                'stock_unit_cost_cents' => $line->stock_unit_cost_cents,
            ])->all()),
            'created_at' => $receipt->created_at->toIso8601ZuluString(),
        ];
    }
}
