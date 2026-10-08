<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Modules\Module;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property Module $module
 * @property bool $enabled
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BranchModule extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return ['module' => Module::class, 'enabled' => 'boolean'];
    }
}
