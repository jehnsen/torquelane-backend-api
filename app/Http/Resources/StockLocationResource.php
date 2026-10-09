<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property StockLocation $resource
 */
final class StockLocationResource extends JsonResource
{
    public function __construct(StockLocation $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = $this->resource;

        return [
            'id' => $location->id,
            'branch_id' => $location->branch_id,
            'kind' => $location->kind,
            'name' => $location->name,
            'is_active' => $location->is_active,
        ];
    }
}
