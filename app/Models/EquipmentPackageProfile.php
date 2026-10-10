<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many hours one completed job of a package puts on a tool (the source's
 * `service_equipment`). A tool ages only on the packages it is mapped to; a
 * tool mapped to none never ages from bookings.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $package_id
 * @property string $equipment_id
 * @property BigDecimal $hours_per_job
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Equipment $equipment
 * @property-read DetailingPackage $package
 */
final class EquipmentPackageProfile extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return ['hours_per_job' => DecimalCast::class];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<DetailingPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(DetailingPackage::class, 'package_id');
    }
}
