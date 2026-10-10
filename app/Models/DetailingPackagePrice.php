<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Detailing\SizeClass;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What a package costs for one size class, and how long it takes when that
 * differs from the package's own duration.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $package_id
 * @property SizeClass $size_class
 * @property int $price_cents
 * @property string|null $duration_text
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DetailingPackagePrice extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return ['size_class' => SizeClass::class, 'price_cents' => 'integer'];
    }
}
