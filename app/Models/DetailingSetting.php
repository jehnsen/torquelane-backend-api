<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Detailing\SizeClass;
use App\Domain\Detailing\SlotGrid;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One branch's detailing settings: the source's `settings` singleton, per
 * branch. A branch with no row yet works on the defaults (`defaults()`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property int $detailer_pool_size
 * @property int $default_detailers_per_booking
 * @property int $closing_minutes
 * @property bool $public_booking_enabled
 * @property string|null $public_booking_notice
 * @property bool $inspection_required
 * @property array<string, string> $size_multipliers
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DetailingSetting extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    /** How much of a package's materials each size uses, until the branch says otherwise. */
    public const array DEFAULT_SIZE_MULTIPLIERS = ['small' => '0.800', 'medium' => '1.000', 'large' => '1.250', 'xl' => '1.500'];

    protected $attributes = [
        'detailer_pool_size' => 5,
        'default_detailers_per_booking' => 1,
        'closing_minutes' => SlotGrid::DEFAULT_CLOSING_MINUTES,
        'public_booking_enabled' => false,
        'inspection_required' => true,
        'size_multipliers' => '{"small":"0.800","medium":"1.000","large":"1.250","xl":"1.500"}',
    ];

    protected function casts(): array
    {
        return [
            'detailer_pool_size' => 'integer',
            'default_detailers_per_booking' => 'integer',
            'closing_minutes' => 'integer',
            'public_booking_enabled' => 'boolean',
            'inspection_required' => 'boolean',
            'size_multipliers' => 'array',
        ];
    }

    /** The settings a branch works on before anyone has saved any. Not persisted. */
    public static function defaults(string $organizationId, string $branchId): self
    {
        $settings = new self;
        $settings->forceFill(['organization_id' => $organizationId, 'branch_id' => $branchId]);

        return $settings;
    }

    public function multiplierFor(SizeClass $size): string
    {
        return $this->size_multipliers[$size->value] ?? self::DEFAULT_SIZE_MULTIPLIERS[$size->value];
    }
}
