<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Consent;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Consent $resource
 */
final class ConsentResource extends JsonResource
{
    public function __construct(Consent $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $consent = $this->resource;

        return [
            'id' => $consent->id,
            'customer_account_id' => $consent->customer_account_id,
            'contact_id' => $consent->contact_id,
            'purpose' => $consent->purpose->value,
            'granted' => $consent->granted,
            'channel' => $consent->channel->value,
            'captured_at' => $consent->captured_at->toIso8601ZuluString(),
            // Which staff member recorded it is the shop's business, not the customer's.
            'captured_by' => app(TenantManager::class)->context()?->isStaff() === true ? $consent->captured_by : null,
            'evidence' => $consent->evidence,
            'created_at' => $consent->created_at->toIso8601ZuluString(),
        ];
    }
}
