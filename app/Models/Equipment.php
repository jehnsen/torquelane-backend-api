<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tool on a branch's detailing floor (the source's `shop_equipment`): the
 * asset record, how it is metered, its service intervals and its rated life.
 * It has no meter column: its runtime is derived (`EquipmentEngine`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property string $category
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $bay_id
 * @property string $status
 * @property string $condition_rating
 * @property CarbonImmutable|null $purchase_date
 * @property int $purchase_cost_cents
 * @property CarbonImmutable|null $warranty_until
 * @property BigDecimal|null $expected_life_hours
 * @property string $meter_type
 * @property BigDecimal $baseline_hours
 * @property int $baseline_cycles
 * @property BigDecimal|null $service_interval_hours
 * @property int|null $service_interval_cycles
 * @property int|null $service_interval_days
 * @property CarbonImmutable|null $last_service_at
 * @property string|null $maintenance_task
 * @property string|null $notes
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Bay|null $bay
 * @property-read Collection<int, EquipmentUsageLog> $usageLogs
 * @property-read Collection<int, EquipmentMaintenanceLog> $maintenanceLogs
 * @property-read Collection<int, EquipmentPackageProfile> $profiles
 */
final class Equipment extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $table = 'equipment';

    protected $attributes = [
        'category' => 'other',
        'status' => 'available',
        'condition_rating' => 'good',
        'purchase_cost_cents' => 0,
        'meter_type' => 'hours',
        'baseline_hours' => '0',
        'baseline_cycles' => 0,
        'is_active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'immutable_date',
            'purchase_cost_cents' => 'integer',
            'warranty_until' => 'immutable_date',
            'expected_life_hours' => DecimalCast::class,
            'baseline_hours' => DecimalCast::class,
            'baseline_cycles' => 'integer',
            'service_interval_hours' => DecimalCast::class,
            'service_interval_cycles' => 'integer',
            'service_interval_days' => 'integer',
            'last_service_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Bay, $this>
     */
    public function bay(): BelongsTo
    {
        return $this->belongsTo(Bay::class);
    }

    /**
     * @return HasMany<EquipmentUsageLog, $this>
     */
    public function usageLogs(): HasMany
    {
        return $this->hasMany(EquipmentUsageLog::class)->orderByDesc('used_at')->orderByDesc('id');
    }

    /**
     * @return HasMany<EquipmentMaintenanceLog, $this>
     */
    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(EquipmentMaintenanceLog::class)->orderByDesc('performed_at')->orderByDesc('id');
    }

    /**
     * @return HasMany<EquipmentPackageProfile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(EquipmentPackageProfile::class);
    }
}
