<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Detailing\SizeClass;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's standing weekly slot (the source's `recurring_schedules`): a
 * package for a vehicle on a day of the week at a time. As in the source it
 * is a standing preference the shop books from; nothing raises bookings by itself.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $customer_account_id
 * @property string|null $vehicle_id
 * @property string $package_id
 * @property SizeClass $size_class
 * @property int $day_of_week
 * @property int $start_minutes
 * @property bool $is_active
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read DetailingPackage $package
 * @property-read CustomerAccount $customerAccount
 * @property-read Vehicle|null $vehicle
 */
final class DetailingRecurringSchedule extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'size_class' => SizeClass::class,
            'day_of_week' => 'integer',
            'start_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<DetailingPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(DetailingPackage::class, 'package_id');
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
