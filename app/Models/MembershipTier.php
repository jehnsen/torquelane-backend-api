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
 * A membership tier: a discount, priority booking, free add-ons.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property BigDecimal $discount_pct
 * @property bool $priority_booking
 * @property list<string> $free_addon_ids
 * @property string|null $perks
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MembershipTier extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['free_addon_ids' => '[]', 'discount_pct' => '0', 'priority_booking' => false, 'is_active' => true, 'position' => 0];

    protected function casts(): array
    {
        return [
            'discount_pct' => DecimalCast::class,
            'priority_booking' => 'boolean',
            'free_addon_ids' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
