<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\Side;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One account in the organization's chart. Organization-wide (no branch);
 * staff with `ledger:view` read it, `ledger:manage` edits it. Once posted to,
 * its code, type and side are fixed (trigger); it is deactivated, never
 * deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property Side $normal_side
 * @property bool $is_active
 * @property bool $is_system
 * @property int $position
 * @property string $description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Account extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['is_active' => true, 'is_system' => false, 'position' => 0, 'description' => ''];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_side' => Side::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'position' => 'integer',
        ];
    }
}
