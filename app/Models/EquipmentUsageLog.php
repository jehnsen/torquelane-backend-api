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
 * Runtime the booking sheet never saw (a test run, a side job). Completed
 * bookings accrue on their own through the package profiles: logging one here
 * as well would count it twice.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $equipment_id
 * @property string|null $detailing_booking_id
 * @property BigDecimal $hours
 * @property int $cycles
 * @property CarbonImmutable $used_at
 * @property string|null $note
 * @property string|null $logged_by
 * @property string $logged_by_name
 * @property CarbonImmutable $created_at
 */
final class EquipmentUsageLog extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['hours' => DecimalCast::class, 'cycles' => 'integer', 'used_at' => 'immutable_datetime'];
    }
}
