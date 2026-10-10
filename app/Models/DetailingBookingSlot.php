<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One (date, slot) a booking occupies (the source's `booking_day_slots`): the
 * authority for capacity and detailer conflicts across days. A `released`
 * row is kept for the record and for a re-open, and counts for nothing.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $booking_id
 * @property CarbonImmutable $date
 * @property int $slot
 * @property bool $released
 * @property CarbonImmutable $created_at
 */
final class DetailingBookingSlot extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $attributes = ['released' => false];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'slot' => 'integer', 'released' => 'boolean'];
    }
}
