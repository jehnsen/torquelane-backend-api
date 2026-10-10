<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vehicle's condition as recorded on a booking (the source's
 * `car_condition_logs`), at check-in or at hand-back, with its damage map and
 * photos. The mileage is a meter reading, not a column here.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $booking_id
 * @property string $customer_account_id
 * @property string|null $vehicle_id
 * @property string $stage
 * @property int $overall_rating
 * @property int|null $exterior_rating
 * @property int|null $interior_rating
 * @property string|null $exterior_condition
 * @property string|null $interior_condition
 * @property string|null $meter_reading_id
 * @property string|null $notes
 * @property string|null $recorded_by
 * @property string $recorded_by_name
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, DetailingDamagePoint> $damagePoints
 * @property-read Collection<int, DetailingInspectionPhoto> $photos
 * @property-read MeterReading|null $meterReading
 */
final class DetailingInspection extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    public const string STAGE_CHECK_IN = 'check_in';

    public const string STAGE_CHECK_OUT = 'check_out';

    public const array STAGES = [self::STAGE_CHECK_IN, self::STAGE_CHECK_OUT];

    public const array CONDITIONS = ['excellent', 'good', 'fair', 'poor'];

    protected function casts(): array
    {
        return [
            'overall_rating' => 'integer',
            'exterior_rating' => 'integer',
            'interior_rating' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<DetailingDamagePoint, $this>
     */
    public function damagePoints(): HasMany
    {
        return $this->hasMany(DetailingDamagePoint::class, 'inspection_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<DetailingInspectionPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(DetailingInspectionPhoto::class, 'inspection_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<MeterReading, $this>
     */
    public function meterReading(): BelongsTo
    {
        return $this->belongsTo(MeterReading::class);
    }
}
