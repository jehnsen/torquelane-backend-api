<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DetailingCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DetailingCategory $resource
 */
final class DetailingCategoryResource extends JsonResource
{
    public function __construct(DetailingCategory $resource)
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
            'branch_id' => $this->resource->branch_id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'color' => $this->resource->color,
            'position' => $this->resource->position,
        ];
    }
}
