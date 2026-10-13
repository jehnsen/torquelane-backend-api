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
            'registered_name' => $vendor->registered_name,
            'tin' => $vendor->tin,
            'address' => $vendor->address,
            'is_vat_registered' => $vendor->is_vat_registered,
            'payment_terms_days' => $vendor->payment_terms_days,
            'default_atc_code' => $vendor->default_atc_code,
            'default_withholding_rate_pct' => $vendor->default_withholding_rate_pct === null ? null : (string) $vendor->default_withholding_rate_pct->strippedOfTrailingZeros(),
            'created_at' => $vendor->created_at->toIso8601ZuluString(),
            'updated_at' => $vendor->updated_at->toIso8601ZuluString(),
        ];
    }
}
