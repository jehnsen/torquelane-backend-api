<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Maintenance\MeterKind;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * When a task was last done on an asset, and the meter then.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $asset_type
 * @property string $asset_id
 * @property string|null $vehicle_id
 * @property string $service_task_id
 * @property MeterKind $meter_kind
 * @property BigDecimal $last_done_value
 * @property CarbonImmutable $last_done_on
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MaintenanceState extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'meter_kind' => MeterKind::class,
            'last_done_value' => DecimalCast::class,
            'last_done_on' => 'immutable_date',
        ];
    }
}
