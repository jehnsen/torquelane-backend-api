<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

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
 * @property BigDecimal $downtime_hours
 * @property string|null $parts
 * @property string|null $notes
 * @property BigDecimal|null $meter_hours
 * @property int|null $meter_cycles
 * @property bool $resets_service
 * @property string|null $recorded_by
 * @property string $recorded_by_name
 * @property CarbonImmutable $created_at
 */
final class EquipmentMaintenanceLog extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $attributes = ['kind' => 'preventive', 'cost_cents' => 0, 'downtime_hours' => '0', 'resets_service' => true];

    protected function casts(): array
    {
        return [
            'performed_at' => 'immutable_datetime',
            'cost_cents' => 'integer',
            'downtime_hours' => DecimalCast::class,
            'meter_hours' => DecimalCast::class,
            'meter_cycles' => 'integer',
            'resets_service' => 'boolean',
        ];
    }
}
