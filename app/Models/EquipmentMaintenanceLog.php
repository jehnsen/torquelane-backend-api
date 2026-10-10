<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of a tool's service history. When `resets_service` is on (the
 * default; off for a clean inspection) logging it stamps the tool's
 * `last_service_at`, which restarts every service interval.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $equipment_id
 * @property string $kind
 * @property CarbonImmutable $performed_at
 * @property string|null $performed_by
 * @property int $cost_cents
 * @property int $parts_cost_cents
 * @property BigDecimal $downtime_hours
 * @property string|null $parts
 * @property string|null $notes
 * @property BigDecimal|null $meter_hours
 * @property int|null $meter_cycles
 * @property bool $resets_service
 * @property string|null $recorded_by
 * @property string $recorded_by_name
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, EquipmentMaintenancePart> $issuedParts
 * @property-read Equipment $equipment
 */
final class EquipmentMaintenanceLog extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $attributes = ['kind' => 'preventive', 'cost_cents' => 0, 'parts_cost_cents' => 0, 'downtime_hours' => '0', 'resets_service' => true];

    protected function casts(): array
    {
        return [
            'performed_at' => 'immutable_datetime',
            'cost_cents' => 'integer',
            'parts_cost_cents' => 'integer',
            'downtime_hours' => DecimalCast::class,
            'meter_hours' => DecimalCast::class,
            'meter_cycles' => 'integer',
            'resets_service' => 'boolean',
        ];
    }

    /** What the entry cost in all: what was paid for it, and the parts it took from stock. */
    public function totalCostCents(): int
    {
        return $this->cost_cents + $this->parts_cost_cents;
    }

    /** @return HasMany<EquipmentMaintenancePart, $this> */
    public function issuedParts(): HasMany
    {
        return $this->hasMany(EquipmentMaintenancePart::class, 'maintenance_log_id')->orderBy('id');
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
