<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property list<string> $skill_tags
 * @property string|null $specialty
 * @property string|null $home_bay_id
 * @property string|null $user_id
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Technician extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    /** Suggested tags; any lowercase tag is accepted. */
    public const array KNOWN_SKILLS = ['mechanic', 'detailer', 'electrician', 'painter', 'tire_specialist'];

    protected $attributes = ['status' => 'active', 'skill_tags' => '[]'];

    protected function casts(): array
    {
        return ['skill_tags' => 'array'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $query->whereIn($this->qualifyColumn('branch_id'), $context->isStaff() ? $context->allowedBranchIds : []);
    }
}
