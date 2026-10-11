<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved definition of a group of customers (`SegmentFilter`). Who is in it is worked out when it is read.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $description
 * @property array<array-key, mixed> $filters
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CrmSegment extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $table = 'crm_segments';

    protected $attributes = ['filters' => '{}'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }
}
