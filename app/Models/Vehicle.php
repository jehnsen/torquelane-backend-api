<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property string $plate_number
 * @property string $plate_normalized
 * @property string|null $make
 * @property string|null $model
 * @property int|null $year
 * @property string|null $vin
 * @property string|null $vin_normalized
 * @property string|null $vehicle_class
 * @property string|null $fuel_type
 * @property string|null $size_class
 * @property string|null $color
 * @property string $status
 * @property string|null $assigned_to
 * @property string|null $department
 * @property string|null $location
 * @property CarbonImmutable|null $acquired_on
 * @property CarbonImmutable|null $registration_expiry
 * @property CarbonImmutable|null $insurance_expiry
 * @property CarbonImmutable|null $driver_licence_expiry
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Vehicle extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const string ASSET_TYPE = 'vehicle';

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'acquired_on' => 'immutable_date',
            'registration_expiry' => 'immutable_date',
            'insurance_expiry' => 'immutable_date',
            'driver_licence_expiry' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * Staff: every vehicle in the organization. Portal: the vehicles their
     * account owns NOW; a vehicle sold on leaves the old owner's view.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $accountId = $context->customerAccountId();
        if ($accountId !== null) {
            $query->where($this->qualifyColumn('customer_account_id'), $accountId);
        }
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /**
     * Every reading row, void rows included (MeterReadings resolves the effective set).
     *
     * @return HasMany<MeterReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * @return HasMany<MaintenanceState, $this>
     */
    public function maintenanceStates(): HasMany
    {
        return $this->hasMany(MaintenanceState::class);
    }

    /**
     * @return HasMany<VehicleOwnership, $this>
     */
    public function ownerships(): HasMany
    {
        return $this->hasMany(VehicleOwnership::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
