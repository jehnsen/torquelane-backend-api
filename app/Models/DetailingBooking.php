<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\SizeClass;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A detailing booking, and from the moment work starts its job card. The
 * package, price, duration and size are as booked. `scheduled_date` and
 * `start_minutes` are what was asked for; `slots` is where the work sits.
 *
 * Never set `status`, `started_at`, `completed_at` or a slot's `released`
 * directly: `ChangeBookingStatus` does, with the log row.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $customer_account_id
 * @property string|null $vehicle_id
 * @property string $reference
 * @property BookingStatus $status
 * @property string $source
 * @property string $package_id
 * @property string $package_name
 * @property string|null $category_name
 * @property SizeClass $size_class
 * @property int $package_price_cents
 * @property string $duration_text
 * @property int $min_detailers
 * @property CarbonImmutable $scheduled_date
 * @property int $start_minutes
 * @property int $detailers_count
 * @property string|null $bay_id
 * @property string $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $vehicle_label
 * @property string $notes
 * @property string|null $cancellation_reason
 * @property list<array{label: string, done: bool, done_by_name: string|null, done_at: string|null}> $checklist
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $inspection_override_reason
 * @property string|null $inspection_overridden_by_name
 * @property string|null $qc_status
 * @property string|null $qc_by
 * @property string|null $qc_by_name
 * @property CarbonImmutable|null $qc_at
 * @property string|null $qc_notes
 * @property CarbonImmutable|null $materials_consumed_at
 * @property int|null $materials_cost_cents
 * @property string|null $public_token_hash
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, DetailingBookingSlot> $slots
 * @property-read Collection<int, DetailingBookingDetailer> $detailers
 * @property-read Collection<int, DetailingBookingAddon> $addons
 * @property-read Collection<int, DetailingBookingStatusLog> $statusLogs
 * @property-read Collection<int, DetailingInspection> $inspections
 * @property-read Collection<int, InvoiceBooking> $invoiceLinks
 * @property-read Bay|null $bay
 * @property-read CustomerAccount $customerAccount
 * @property-read Vehicle|null $vehicle
 */
final class DetailingBooking extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    public const string SOURCE_STAFF = 'staff';

    public const string SOURCE_PUBLIC = 'public';

    public const string SOURCE_RECURRING = 'recurring';

    public const string QC_PASSED = 'passed';

    public const string QC_FAILED = 'failed';

    protected $attributes = ['status' => 'pending', 'source' => 'staff', 'notes' => '', 'checklist' => '[]', 'detailers_count' => 1, 'min_detailers' => 1];

    protected $hidden = ['public_token_hash'];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'size_class' => SizeClass::class,
            'package_price_cents' => 'integer',
            'min_detailers' => 'integer',
            'scheduled_date' => 'immutable_date',
            'start_minutes' => 'integer',
            'detailers_count' => 'integer',
            'checklist' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'qc_at' => 'immutable_datetime',
            'materials_consumed_at' => 'immutable_datetime',
            'materials_cost_cents' => 'integer',
        ];
    }

    /**
     * @return HasMany<DetailingBookingSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(DetailingBookingSlot::class, 'booking_id')->orderBy('date')->orderBy('slot');
    }

    /**
     * @return HasMany<DetailingBookingDetailer, $this>
     */
    public function detailers(): HasMany
    {
        return $this->hasMany(DetailingBookingDetailer::class, 'booking_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<DetailingBookingAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(DetailingBookingAddon::class, 'booking_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<DetailingBookingStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(DetailingBookingStatusLog::class, 'booking_id')->orderBy('changed_at')->orderBy('id');
    }

    /**
     * @return HasMany<DetailingInspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(DetailingInspection::class, 'booking_id')->orderBy('recorded_at')->orderBy('id');
    }

    /**
     * @return HasMany<InvoiceBooking, $this>
     */
    public function invoiceLinks(): HasMany
    {
        return $this->hasMany(InvoiceBooking::class, 'booking_id');
    }

    /**
     * @return BelongsTo<Bay, $this>
     */
    public function bay(): BelongsTo
    {
        return $this->belongsTo(Bay::class);
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

    /** The invoice link still standing (not released by a void), if any. */
    public function standingInvoiceLink(): ?InvoiceBooking
    {
        return $this->invoiceLinks->first(fn (InvoiceBooking $link): bool => $link->released_at === null);
    }

    /**
     * The named detailers' ids, in the order they were named.
     *
     * @return list<string>
     */
    public function detailerIds(): array
    {
        return array_values($this->detailers->map(fn (DetailingBookingDetailer $row): string => $row->technician_id)->all());
    }
}
