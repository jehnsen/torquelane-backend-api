<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FleetPart;
use App\Models\FleetPartUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property FleetPart $resource
 */
final class FleetPartResource extends JsonResource
{
    public function __construct(FleetPart $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $part = $this->resource;

        return [
            'id' => $part->id,
            'customer_account_id' => $part->customer_account_id,
            'sku' => $part->sku,
            'name' => $part->name,
            'category' => $part->category,
            'unit' => $part->unit,
            'unit_cost_cents' => $part->unit_cost_cents,
            'current_stock' => $part->current_stock,
            'reorder_point' => $part->reorder_point,
            /** At or below its reorder point. */
            'needs_reorder' => $part->current_stock <= $part->reorder_point,
            'preferred_vendor' => $part->preferred_vendor,
            'lead_time_days' => $part->lead_time_days,
            'is_active' => $part->is_active,
            'usages' => array_values($part->usages->map(fn (FleetPartUsage $usage): array => [
                'service_task_id' => $usage->service_task_id,
                'quantity_per_service' => $usage->quantity_per_service,
            ])->all()),
            'created_at' => $part->created_at->toIso8601ZuluString(),
            'updated_at' => $part->updated_at->toIso8601ZuluString(),
        ];
    }
}
