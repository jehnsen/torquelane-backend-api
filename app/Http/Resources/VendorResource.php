<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Vendor $resource
 */
final class VendorResource extends JsonResource
{
    public function __construct(Vendor $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $vendor = $this->resource;

        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'is_active' => $vendor->is_active,
            'created_at' => $vendor->created_at->toIso8601ZuluString(),
            'updated_at' => $vendor->updated_at->toIso8601ZuluString(),
        ];
    }
}
