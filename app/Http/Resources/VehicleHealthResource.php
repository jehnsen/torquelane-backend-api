<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Fleet\FleetThresholds;
use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\VehicleHealth;
use App\Models\ServiceTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /vehicles/{id}/health: the engine's full output for one vehicle.
 *
 * @property VehicleHealth $resource
 */
final class VehicleHealthResource extends JsonResource
{
    /**
     * @param  array<string, ServiceTask>  $tasks
     */
    public function __construct(VehicleHealth $resource, private readonly array $tasks, private readonly string $evaluatedOn)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $health = $this->resource;
        $item = fn (PmsItem $item): array => (new PmsItemResource($item, $this->tasks))->resolve($request);

        return [
            'vehicle_id' => $health->vehicle->id,
            'evaluated_on' => $this->evaluatedOn,
            // ok | due_soon | overdue (the worst item)
            'status' => $health->status,
            'health_score' => $health->healthScore,
            'overdue_count' => $health->overdueCount,
            'due_soon_count' => $health->dueSoonCount,
            'next_item' => $health->nextItem === null ? null : $item($health->nextItem),
            // Most urgent first.
            'items' => array_map($item, $health->items),
            'odometer' => [
                'value' => $health->vehicle->odometer,
                'read_on' => $health->vehicle->odometerReadAt,
                'avg_daily_km' => $health->vehicle->avgDailyKm,
            ],
            'thresholds' => [
                'due_soon_km' => FleetThresholds::DUE_SOON_KM,
                'due_soon_days' => FleetThresholds::DUE_SOON_DAYS,
            ],
        ];
    }
}
