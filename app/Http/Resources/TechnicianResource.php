<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Technician $resource
 */
final class TechnicianResource extends JsonResource
{
    public function __construct(Technician $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $technician = $this->resource;

        return [
            'id' => $technician->id,
            'branch_id' => $technician->branch_id,
            'name' => $technician->name,
            'skill_tags' => $technician->skill_tags,
            'specialty' => $technician->specialty,
            'home_bay_id' => $technician->home_bay_id,
            'user_id' => $technician->user_id,
            'status' => $technician->status,
            'created_at' => $technician->created_at->toIso8601ZuluString(),
            'updated_at' => $technician->updated_at->toIso8601ZuluString(),
        ];
    }
}
