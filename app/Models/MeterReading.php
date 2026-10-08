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
 * Append-only (the table's triggers refuse UPDATE and DELETE). A correction
 * is a void row pointing at the reading it voids.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $asset_type
 * @property string $asset_id
 * @property string|null $vehicle_id
 * @property MeterKind $meter_kind
 * @property BigDecimal|null $value
 * @property CarbonImmutable $read_on
 * @property string $source
 * @property string|null $recorded_by
 * @property string|null $voids_reading_id
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 */
final class MeterReading extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    public const array SOURCES = ['manual', 'check_in', 'work_order', 'import', 'telematics'];

    protected function casts(): array
    {
        return [
            'meter_kind' => MeterKind::class,
            'value' => DecimalCast::class,
            'read_on' => 'immutable_date',
        ];
    }

    public function isVoid(): bool
    {
        return $this->voids_reading_id !== null;
    }
}
