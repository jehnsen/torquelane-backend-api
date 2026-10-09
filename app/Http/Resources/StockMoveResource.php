<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockMove;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of an item's movements. `quantity` is signed; `value_cents` is
 * |quantity| × the unit cost the move was recorded at; `source_reference` is
 * the document behind it (a receipt, transfer, count, or work order).
 *
 * @property StockMove $resource
 */
final class StockMoveResource extends JsonResource
{
    public function __construct(StockMove $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $move = $this->resource;
        $attributes = $move->getAttributes();

        return [
            'id' => $move->id,
            'item' => InventoryJson::item($move->item),
            'branch_id' => $move->branch_id,
            'location_id' => $move->location_id,
            'move_type' => $move->move_type->value,
            'quantity' => InventoryJson::quantity($move->quantity),
            'unit_cost_cents' => $move->unit_cost_cents,
            'value_cents' => $move->valueCents(),
            'source_type' => $move->source_type->value,
            'source_id' => $move->source_id,
            'source_reference' => is_string($attributes['source_reference'] ?? null) ? $attributes['source_reference'] : null,
            'source_document_id' => is_string($attributes['source_document_id'] ?? null) ? $attributes['source_document_id'] : null,
            'occurred_at' => $move->occurred_at->toIso8601ZuluString(),
            'actor_name' => $move->actor_name,
            'reason' => $move->reason,
            'negative_flag' => $move->negative_flag,
        ];
    }
}
