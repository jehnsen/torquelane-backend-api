<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property string|null $focus
 * @property BigDecimal $capacity_hours_per_day
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Bay extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['capacity_hours_per_day' => DecimalCast::class];
    }

    /**
     * Staff: bays in their allowed branches. Portal: none.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $query->whereIn($this->qualifyColumn('branch_id'), $context->isStaff() ? $context->allowedBranchIds : []);
    }
}
