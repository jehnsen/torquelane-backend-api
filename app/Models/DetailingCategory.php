<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A heading on a branch's detailing menu (the source's `service_categories`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property string $slug
 * @property string|null $color
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DetailingCategory extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $attributes = ['position' => 0];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
