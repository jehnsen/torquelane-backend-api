<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A slot the shop has closed to new bookings (the source's `blocked_slots`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property CarbonImmutable $date
 * @property int $slot
 * @property string $label
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DetailingBlockedSlot extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $attributes = ['label' => 'Unavailable'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'slot' => 'integer'];
    }
}
