<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prepaid credit used (append-only): the share of the package's price it earned.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $prepaid_package_id
 * @property string $branch_id
 * @property string|null $detailing_booking_id
 * @property int $credits
 * @property int $amount_cents
 * @property CarbonImmutable $redeemed_on
 * @property string $redeemed_by_name
 * @property CarbonImmutable $created_at
 * @property-read PrepaidPackage $package
 */
final class PrepaidRedemption extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $attributes = ['credits' => 1];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'amount_cents' => 'integer',
            'redeemed_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<PrepaidPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PrepaidPackage::class, 'prepaid_package_id');
    }
}
