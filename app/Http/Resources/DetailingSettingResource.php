<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Detailing\SizeClass;
use App\Domain\Detailing\SlotGrid;
use App\Models\DetailingSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A branch's detailing settings. `active_detailers` is the branch's roster of
 * active detailers (the source kept the pool in step with it by hand) and
 * `peak_heads` the most its bookings hold in one slot: the pool cannot go
 * below it.
 *
 * @property DetailingSetting $resource
 */
final class DetailingSettingResource extends JsonResource
{
    public function __construct(DetailingSetting $resource, private readonly int $activeDetailers, private readonly int $peakHeads)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->resource;

        return [
            'branch_id' => $settings->branch_id,
            'saved' => $settings->exists,
            'detailer_pool_size' => $settings->detailer_pool_size,
            'default_detailers_per_booking' => $settings->default_detailers_per_booking,
            'closing_minutes' => $settings->closing_minutes,
            'closing_time' => SlotGrid::time($settings->closing_minutes),
            'closing_label' => SlotGrid::label($settings->closing_minutes),
            'public_booking_enabled' => $settings->public_booking_enabled,
            'public_booking_notice' => $settings->public_booking_notice,
            'inspection_required' => $settings->inspection_required,
            'size_multipliers' => array_map(fn (SizeClass $size): array => [
                'size_class' => $size->value,
                'size_label' => $size->label(),
                'factor' => $settings->multiplierFor($size),
            ], SizeClass::cases()),
            'active_detailers' => $this->activeDetailers,
            'peak_heads' => $this->peakHeads,
            'grid' => [
                'slot_minutes' => SlotGrid::SLOT_MINUTES,
                'opening_minutes' => SlotGrid::OPENING_MINUTES,
                'lunch_start_minutes' => SlotGrid::LUNCH_START_MINUTES,
                'lunch_end_minutes' => SlotGrid::LUNCH_END_MINUTES,
                'grid_end_minutes' => SlotGrid::GRID_END_MINUTES,
            ],
        ];
    }
}
