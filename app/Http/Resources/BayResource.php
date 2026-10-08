<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Bay $resource
 */
final class BayResource extends JsonResource
{
    public function __construct(Bay $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bay = $this->resource;

        return [
            'id' => $bay->id,
            'branch_id' => $bay->branch_id,
            'name' => $bay->name,
            'focus' => $bay->focus,
            'capacity_hours_per_day' => (string) $bay->capacity_hours_per_day,
            'status' => $bay->status,
            'created_at' => $bay->created_at->toIso8601ZuluString(),
            'updated_at' => $bay->updated_at->toIso8601ZuluString(),
        ];
    }
}
