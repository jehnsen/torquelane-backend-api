<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Fleet\VehicleView;
use App\Domain\Fleet\Compliance;
use App\Domain\Shared\WebFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vehicle with its computed state: current odometer and daily rate (from
 * readings), PMS summary (where repair_pms is active), compliance (from the
 * documents the caller can see), LTO renewal month.
 *
 * @property VehicleView $resource
 */
final class VehicleResource extends JsonResource
{
    public function __construct(VehicleView $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $vehicle = $view->vehicle;
        $health = $view->health;

        return [
            'id' => $vehicle->id,
            'customer_account_id' => $vehicle->customer_account_id,
            'plate_number' => $vehicle->plate_number,
            'plate_normalized' => $vehicle->plate_normalized,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'vin' => $vehicle->vin,
            'vehicle_class' => $vehicle->vehicle_class,
            'fuel_type' => $vehicle->fuel_type,
            'size_class' => $vehicle->size_class,
            'color' => $vehicle->color,
            'status' => $vehicle->status,
            'assigned_to' => $vehicle->assigned_to,
            'department' => $vehicle->department,
            'location' => $vehicle->location,
            'acquired_on' => $vehicle->acquired_on?->toDateString(),
            'registration_expiry' => $vehicle->registration_expiry?->toDateString(),
            'insurance_expiry' => $vehicle->insurance_expiry?->toDateString(),
            'driver_licence_expiry' => $vehicle->driver_licence_expiry?->toDateString(),
            'lto_renewal_month' => Compliance::plateEndingRenewalMonth($vehicle->plate_number),
            'odometer' => [
                'value' => $view->facts->odometer,
                'label' => WebFormat::km($view->facts->odometer),
                'read_on' => $view->facts->odometerReadAt,
                'age_days' => $view->odometerAgeDays,
                // Older than the stale threshold: re-read the dash, don't trust it.
                'stale' => $view->odometerStale,
                'avg_daily_km' => $view->facts->avgDailyKm,
            ],
            'pms' => $health === null ? null : [
                'status' => $health->status,
                'health_score' => $health->healthScore,
                'overdue_count' => $health->overdueCount,
                'due_soon_count' => $health->dueSoonCount,
                'next_item' => $health->nextItem === null ? null : [
                    'service_task_id' => $health->nextItem->task->id,
                    'name' => $health->nextItem->task->name,
                    'status' => $health->nextItem->status,
                    'due_date' => $health->nextItem->dueDate,
                    'days_remaining' => $health->nextItem->daysRemaining,
                    'due_label' => WebFormat::dayDelta($health->nextItem->daysRemaining),
                    'governed_by' => $health->nextItem->governedBy,
                    // For the list's progress meter: km left (negative = past) and the interval used.
                    'km_remaining' => $health->nextItem->kmRemaining,
                    'due_odometer' => $health->nextItem->dueOdometer,
                    'progress' => $health->nextItem->progress,
                ],
            ],
            // expired | expiring | ok
            'compliance_status' => $view->complianceStatus,
            'archived_at' => $vehicle->archived_at?->toIso8601ZuluString(),
            'created_at' => $vehicle->created_at->toIso8601ZuluString(),
            'updated_at' => $vehicle->updated_at->toIso8601ZuluString(),
        ];
    }
}
