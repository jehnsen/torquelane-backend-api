<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\StockLedger;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One transfer document: goods out of one location, into another, at the
 * source's average cost. `can_reverse` is true until it (or it being itself
 * a reversal) rules that out.
 *
 * @property StockTransfer $resource
 */
final class StockTransferResource extends JsonResource
{
    public function __construct(StockTransfer $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transfer = $this->resource;
        $transfer->loadMissing(['lines.item', 'reversal']);

        $value = BigDecimal::zero();
        foreach ($transfer->lines as $line) {
            $value = $value->plus($line->quantity->multipliedBy($line->unit_cost_cents));
        }

        return [
            'id' => $transfer->id,
            'reference' => $transfer->reference,
            'from_branch_id' => $transfer->from_branch_id,
            'from_location_id' => $transfer->from_location_id,
            'to_branch_id' => $transfer->to_branch_id,
            'to_location_id' => $transfer->to_location_id,
            'reverses_transfer_id' => $transfer->reverses_transfer_id,
            'reversed_by_transfer_id' => $transfer->reversal?->id,
            'can_reverse' => $transfer->reverses_transfer_id === null && $transfer->reversal === null,
            'notes' => $transfer->notes,
            'transferred_on' => $transfer->transferred_on->toDateString(),
            'created_by_name' => $transfer->created_by_name,
            'total_value_cents' => StockLedger::roundCents($value),
            'lines' => array_values($transfer->lines->map(fn (StockTransferLine $line): array => [
                'id' => $line->id,
                'item' => InventoryJson::item($line->item),
                'quantity' => InventoryJson::quantity($line->quantity),
                'unit_cost_cents' => $line->unit_cost_cents,
                'value_cents' => StockLedger::moveValue($line->quantity, $line->unit_cost_cents),
            ])->all()),
            'created_at' => $transfer->created_at->toIso8601ZuluString(),
        ];
    }
}
