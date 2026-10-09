<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Branch $resource
 */
final class BranchResource extends JsonResource
{
    public function __construct(Branch $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $branch = $this->resource;

        return [
            'id' => $branch->id,
            'organization_id' => $branch->organization_id,
            'name' => $branch->name,
            'slug' => $branch->slug,
            'address' => $branch->address,
            'contact_email' => $branch->contact_email,
            'contact_phone' => $branch->contact_phone,
            'tin' => $branch->tin,
            'branch_code' => $branch->branch_code,
            'is_vat_registered' => $branch->is_vat_registered,
            'prices_include_vat' => $branch->prices_include_vat,
            'negative_stock_policy' => $branch->negative_stock_policy->value,
            'timezone' => $branch->timezone,
            'brand_name' => $branch->brand_name,
            'logo_url' => $branch->logo_url,
            'brand_color' => $branch->brand_color,
            'theme_tokens' => $branch->theme_tokens === null ? null : (object) $branch->theme_tokens,
            'status' => $branch->status,
            'created_at' => $branch->created_at->toIso8601ZuluString(),
            'updated_at' => $branch->updated_at->toIso8601ZuluString(),
        ];
    }
}
