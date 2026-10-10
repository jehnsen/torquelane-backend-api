<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A mark on an inspection's damage map: a point on the top-down outline of
 * the vehicle (thousandths of its width and length) and what is there.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $inspection_id
 * @property int $x_permille
 * @property int $y_permille
 * @property string $kind
 * @property string $note
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DetailingDamagePoint extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const array KINDS = ['scratch', 'dent', 'chip', 'crack', 'stain', 'other'];

    protected $attributes = ['kind' => 'other', 'position' => 0];

    protected function casts(): array
    {
        return ['x_permille' => 'integer', 'y_permille' => 'integer', 'position' => 'integer'];
    }
}
