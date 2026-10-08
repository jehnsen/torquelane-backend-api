<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MeterReading;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reading row. A void row has no value and names the reading it voids.
 *
 * @property MeterReading $resource
 */
final class MeterReadingResource extends JsonResource
{
    public function __construct(MeterReading $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reading = $this->resource;
        $staff = app(TenantManager::class)->context()?->isStaff() ?? false;

        return [
            'id' => $reading->id,
            'vehicle_id' => $reading->vehicle_id,
            'meter_kind' => $reading->meter_kind->value,
            'value' => $reading->value === null ? null : (string) $reading->value,
            'read_on' => $reading->read_on->toDateString(),
            'source' => $reading->source,
            // Which staff member recorded it is the shop's business.
            'recorded_by' => $staff ? $reading->recorded_by : null,
            'voids_reading_id' => $reading->voids_reading_id,
            'void_reason' => $reading->void_reason,
            'created_at' => $reading->created_at->toIso8601ZuluString(),
        ];
    }
}
