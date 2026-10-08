<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The provider's approved third-party vendors (subcontractors), organization
 * wide. Work orders keep a vendor's name as text (the historical label), so
 * removing a vendor never rewrites a past job.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Vendor extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
