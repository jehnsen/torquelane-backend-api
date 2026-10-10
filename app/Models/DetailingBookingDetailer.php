<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A detailer named on a booking (the source's `bookings.detailers_assigned`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $booking_id
 * @property string $technician_id
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property-read Technician $technician
 */
final class DetailingBookingDetailer extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<Technician, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
