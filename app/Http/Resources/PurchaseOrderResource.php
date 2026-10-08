<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\PurchaseOrders\ProgressPurchaseOrder;
use App\Domain\PurchaseOrders\PurchaseOrderMachine;
use App\Domain\PurchaseOrders\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderEvent;
use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A purchase order with its lines, the due items each line covers, its
 * status history, and what the caller may do next. `can_send` is the
 * server's answer to "is issuing this within my band" (the frontend renders
 * it; it never computes it).
 *
 * @property PurchaseOrder $resource
 */
final class PurchaseOrderResource extends JsonResource
{
    public function __construct(PurchaseOrder $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'id' => $order->id,
            'customer_account_id' => $order->customer_account_id,
            'reference' => $order->reference,
            'vendor' => $order->vendor,
            'status' => $order->status->value,
            'next_statuses' => array_map(fn (PurchaseOrderStatus $s): string => $s->value, PurchaseOrderMachine::nextStatuses($order->status)),
            'can_send' => app(ProgressPurchaseOrder::class)->canSend($order),
            'created_on' => $order->created_on->toDateString(),
            'created_by_name' => $order->created_by_name,
            'notes' => $order->notes,
            'total_cents' => $order->total_cents,
            'line_count' => $order->lines->count(),
            'lines' => array_values($order->lines->map(fn (PurchaseOrderLine $line): array => [
                'id' => $line->id,
                'fleet_part_id' => $line->fleet_part_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_cost_cents' => $line->unit_cost_cents,
                'line_total_cents' => $line->line_total_cents,
                'service_task_ids' => array_values($line->serviceTasks->modelKeys()),
                'vehicle_ids' => array_values($line->vehicles->modelKeys()),
            ])->all()),
            'sent_at' => $order->sent_at?->toIso8601ZuluString(),
            'sent_by_name' => $order->sent_by_name,
            'received_at' => $order->received_at?->toIso8601ZuluString(),
            'received_by_name' => $order->received_by_name,
            'cancelled_at' => $order->cancelled_at?->toIso8601ZuluString(),
            'cancelled_by_name' => $order->cancelled_by_name,
            'cancellation_reason' => $order->cancellation_reason,
            'events' => array_values($order->events->map(fn (PurchaseOrderEvent $event): array => [
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
