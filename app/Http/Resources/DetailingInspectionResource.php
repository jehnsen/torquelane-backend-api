<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DetailingDamagePoint;
use App\Models\DetailingInspection;
use App\Models\DetailingInspectionPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vehicle's inspection on a booking: ratings, condition, the mileage read
 * (a meter reading), the damage map and the photos. A photo is opened through
 * `GET /documents/{document_id}/download`, like any document.
 *
 * @property DetailingInspection $resource
 */
final class DetailingInspectionResource extends JsonResource
{
    public function __construct(DetailingInspection $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inspection = $this->resource;
        $inspection->loadMissing(['damagePoints', 'photos.document', 'meterReading']);
        $mileage = $inspection->meterReading?->value;

        return [
            'id' => $inspection->id,
            'booking_id' => $inspection->booking_id,
            'branch_id' => $inspection->branch_id,
            'vehicle_id' => $inspection->vehicle_id,
            'stage' => $inspection->stage,
            'overall_rating' => $inspection->overall_rating,
            'exterior_rating' => $inspection->exterior_rating,
            'interior_rating' => $inspection->interior_rating,
            'exterior_condition' => $inspection->exterior_condition,
            'interior_condition' => $inspection->interior_condition,
            'mileage' => $mileage === null ? null : $mileage->toInt(),
            'meter_reading_id' => $inspection->meter_reading_id,
            'notes' => $inspection->notes,
            'damage_points' => array_values($inspection->damagePoints->map(fn (DetailingDamagePoint $point): array => [
                'id' => $point->id,
                'x_permille' => $point->x_permille,
                'y_permille' => $point->y_permille,
                'kind' => $point->kind,
                'note' => $point->note,
            ])->all()),
            'photos' => array_values($inspection->photos->map(fn (DetailingInspectionPhoto $photo): array => [
                'id' => $photo->id,
                'document_id' => $photo->document_id,
                'phase' => $photo->phase,
                'caption' => $photo->caption,
                'name' => $photo->document->name,
                'mime_type' => $photo->document->mime_type,
                'size_bytes' => $photo->document->size_bytes,
            ])->all()),
            'recorded_by_name' => $inspection->recorded_by_name,
            'recorded_at' => $inspection->recorded_at->utc()->toIso8601ZuluString(),
        ];
    }
}
