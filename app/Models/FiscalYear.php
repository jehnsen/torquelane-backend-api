<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar year whose books are closed: its profit or loss has been moved
 * to Retained Earnings by the closing entry this row is the source of. Made
 * once, final (trigger).
 *
 * @property string $id
 * @property string $organization_id
 * @property int $year
 * @property string $status
 * @property int $net_income_cents
 * @property CarbonImmutable $closed_at
 * @property string|null $closed_by
 * @property string $closed_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class FiscalYear extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'closed'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'net_income_cents' => 'integer',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
