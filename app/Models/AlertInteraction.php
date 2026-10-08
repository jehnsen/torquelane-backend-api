<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One user's read/dismiss state for one derived alert, in one scope bucket.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $scope_key
 * @property string $alert_id
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $dismissed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AlertInteraction extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime', 'dismissed_at' => 'immutable_datetime'];
    }
}
