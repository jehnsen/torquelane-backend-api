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
 * An extra on a booking (the source's `bookings.add_ons`): from the branch's
 * add-on list at that size's price, or typed in at the counter.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $booking_id
 * @property string|null $addon_id
 * @property string $name
 * @property BigDecimal $quantity
 * @property int $unit_price_cents
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DetailingBookingAddon extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return ['quantity' => DecimalCast::class, 'unit_price_cents' => 'integer', 'position' => 'integer'];
    }
}
