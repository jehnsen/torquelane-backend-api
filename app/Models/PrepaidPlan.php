<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A prepaid package on sale: so many credits for a price.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property int $price_cents
 * @property int $credits
 * @property string|null $detailing_package_id
 * @property int|null $valid_days
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PrepaidPlan extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'credits' => 'integer',
            'valid_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
