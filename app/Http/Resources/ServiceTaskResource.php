<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServiceTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ServiceTask $resource
 */
final class ServiceTaskResource extends JsonResource
{
    public function __construct(ServiceTask $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $task = $this->resource;

        return [
            'id' => $task->id,
            'code' => $task->code,
            'name' => $task->name,
            'category' => $task->category,
            'interval_km' => $task->interval_km,
            'interval_months' => $task->interval_months,
            'estimated_cost_cents' => $task->estimated_cost_cents->getMinorAmount()->toInt(),
            'estimated_hours' => (string) $task->estimated_hours,
            'critical' => $task->critical,
            'is_active' => $task->is_active,
            'position' => $task->position,
            'updated_at' => $task->updated_at->toIso8601ZuluString(),
        ];
    }
}
