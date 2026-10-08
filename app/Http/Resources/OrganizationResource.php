<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Organization $resource
 */
final class OrganizationResource extends JsonResource
{
    public function __construct(Organization $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $organization = $this->resource;

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'legal_name' => $organization->legal_name,
            'tin' => $organization->tin,
            'contact_email' => $organization->contact_email,
            'contact_phone' => $organization->contact_phone,
            'address' => $organization->address,
            'support_email' => $organization->support_email,
            'logo_url' => $organization->logo_url,
            'brand_color' => $organization->brand_color,
            'theme_tokens' => $organization->theme_tokens === null ? null : (object) $organization->theme_tokens,
            'status' => $organization->status,
            'created_at' => $organization->created_at->toIso8601ZuluString(),
            'updated_at' => $organization->updated_at->toIso8601ZuluString(),
        ];
    }
}
