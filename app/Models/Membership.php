<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer account's membership of a tier. At most one is active.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property string $tier_id
 * @property CarbonImmutable $started_on
 * @property CarbonImmutable|null $expires_on
 * @property string $status
 * @property string|null $notes
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read MembershipTier $tier
 */
final class Membership extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'started_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<MembershipTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'tier_id');
    }
}
