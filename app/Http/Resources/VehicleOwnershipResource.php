<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VehicleOwnership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Staff only: ownership history names other customer accounts.
 *
 * @property VehicleOwnership $resource
 */
final class VehicleOwnershipResource extends JsonResource
{
    public function __construct(VehicleOwnership $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'vehicle_id' => $this->resource->vehicle_id,
            'customer_account_id' => $this->resource->customer_account_id,
            'from_date' => $this->resource->from_date->toDateString(),
            'to_date' => $this->resource->to_date?->toDateString(),
            'current' => $this->resource->to_date === null,
        ];
    }
}
