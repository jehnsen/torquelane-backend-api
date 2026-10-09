<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Inventory\ShopOrderView;
use App\Domain\Inventory\ReceiptPlan;
use App\Models\GoodsReceipt;
use App\Models\ShopPurchaseOrderEvent;
use App\Models\ShopPurchaseOrderLine;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A purchase order the shop raised. `status` is derived: the draft / issued /
 * cancelled decision, and (once issued) partially_received or received as the
 * goods receipts take quantity in. Quantities are in the purchase unit.
 * `can_*` say what the order's own state allows now.
 *
 * @property ShopOrderView $resource
 */
final class ShopPurchaseOrderResource extends JsonResource
{
    public function __construct(ShopOrderView $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource->order;
        $received = $this->resource->received;
        $status = $order->derivedStatus($received);
        $order->loadMissing(['lines.item', 'events', 'receipts']);

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'branch_id' => $order->branch_id,
            'vendor_id' => $order->vendor_id,
            'vendor_name' => $order->vendor_name,
            'status' => $status->value,
            'notes' => $order->notes,
            'created_on' => $order->created_on->toDateString(),
            'expected_on' => $order->expected_on?->toDateString(),
            'created_by_name' => $order->created_by_name,
            'total_cents' => $order->total_cents,
            'issued_at' => $order->issued_at?->toIso8601ZuluString(),
            'issued_by_name' => $order->issued_by_name,
            'cancelled_at' => $order->cancelled_at?->toIso8601ZuluString(),
            'cancelled_by_name' => $order->cancelled_by_name,
            'cancellation_reason' => $order->cancellation_reason,
            'can_edit' => $status->value === 'draft',
            'can_issue' => $status->value === 'draft' && $order->lines->isNotEmpty(),
            'can_receive' => $status->canReceive(),
            'can_cancel' => $status->canCancel(),
            'lines' => array_values($order->lines->map(fn (ShopPurchaseOrderLine $line): array => [
                'id' => $line->id,
                'position' => $line->position,
                'item' => $line->item === null ? null : InventoryJson::item($line->item),
                'description' => $line->description,
                'purchase_uom' => $line->item->purchase_uom ?? $line->item->uom ?? null,
                'purchase_uom_factor' => $line->item === null ? '1.000' : InventoryJson::quantity($line->item->purchase_uom_factor),
                'quantity' => InventoryJson::quantity($line->quantity),
                'unit_cost_cents' => $line->unit_cost_cents,
                'line_total_cents' => $line->line_total_cents,
                'received_quantity' => InventoryJson::quantity($received[$line->id] ?? BigDecimal::zero()),
                'outstanding_quantity' => InventoryJson::quantity(ReceiptPlan::remaining((string) $line->quantity, (string) ($received[$line->id] ?? BigDecimal::zero()))),
                'work_order_line_id' => $line->work_order_line_id,
            ])->all()),
            'receipts' => array_values($order->receipts->map(fn (GoodsReceipt $receipt): array => [
                'id' => $receipt->id,
                'reference' => $receipt->reference,
                'status' => $receipt->status->value,
                'received_on' => $receipt->received_on->toDateString(),
                'total_cents' => $receipt->total_cents,
            ])->all()),
            'history' => array_values($order->events->map(fn (ShopPurchaseOrderEvent $event): array => [
                'id' => $event->id,
                'status' => $event->status->value,
                'at' => $event->at->toIso8601ZuluString(),
                'actor_name' => $event->actor_name,
                'note' => $event->note,
            ])->all()),
            'created_at' => $order->created_at->toIso8601ZuluString(),
            'updated_at' => $order->updated_at->toIso8601ZuluString(),
        ];
    }
}
