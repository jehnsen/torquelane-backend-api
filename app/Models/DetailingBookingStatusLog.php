<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (triggers): one row per status change of a booking, and one
 * with no `from_status` for the booking being made. Never save a loaded row.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $booking_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $notes
 * @property string|null $actor_id
 * @property string $actor_name
 * @property CarbonImmutable $changed_at
 */
final class DetailingBookingStatusLog extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }
}
