<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $vehicle_id
 * @property string $customer_account_id
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable|null $to_date
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class VehicleOwnership extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return ['from_date' => 'immutable_date', 'to_date' => 'immutable_date'];
    }
}
