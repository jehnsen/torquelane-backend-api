<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\CountStatus;
use App\Domain\Inventory\StockLedger;
use App\Models\StockCount;
use App\Models\StockCountLine;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A count sheet. While open, `expected_quantity` is what the books held when
 * the sheet was drawn; posting refreshes it to what they held then, and fixes
 * each line's variance and the cost it was adjusted at. `summary` is the
 * sheet in numbers.
 *
 * @property StockCount $resource
 */
final class StockCountResource extends JsonResource
{
    public function __construct(StockCount $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $count = $this->resource;
        $count->loadMissing('lines.item');

        $counted = 0;
        $varied = 0;
        $net = BigDecimal::zero();
        foreach ($count->lines as $line) {
            $counted += $line->counted_quantity === null ? 0 : 1;
            if ($line->variance_quantity !== null && ! $line->variance_quantity->isZero()) {
                $varied++;
                $net = $net->plus($line->variance_quantity->multipliedBy($line->unit_cost_cents ?? 0));
            }
        }

        return [
            'id' => $count->id,
            'reference' => $count->reference,
            'branch_id' => $count->branch_id,
            'location_id' => $count->location_id,
            'status' => $count->status->value,
            'reason' => $count->reason,
            'notes' => $count->notes,
            'created_on' => $count->created_on->toDateString(),
            'created_by_name' => $count->created_by_name,
            'posted_at' => $count->posted_at?->toIso8601ZuluString(),
            'posted_by_name' => $count->posted_by_name,
            'cancelled_at' => $count->cancelled_at?->toIso8601ZuluString(),
            'cancelled_by_name' => $count->cancelled_by_name,
            'can_edit' => $count->status === CountStatus::Open,
            'summary' => [
                'lines' => $count->lines->count(),
                'counted_lines' => $counted,
                'variance_lines' => $varied,
                'net_variance_value_cents' => StockLedger::roundCents($net),
            ],
            'lines' => array_values($count->lines->map(fn (StockCountLine $line): array => [
                'id' => $line->id,
                'item' => InventoryJson::item($line->item),
                'expected_quantity' => InventoryJson::quantity($line->expected_quantity),
                'counted_quantity' => InventoryJson::quantity($line->counted_quantity),
                'variance_quantity' => InventoryJson::quantity($line->variance_quantity),
                'unit_cost_cents' => $line->unit_cost_cents,
                'variance_value_cents' => $line->variance_quantity === null ? null : StockLedger::roundCents($line->variance_quantity->multipliedBy($line->unit_cost_cents ?? 0)),
                'reason' => $line->reason,
            ])->all()),
            'created_at' => $count->created_at->toIso8601ZuluString(),
        ];
    }
}
