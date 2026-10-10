<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What a wall screen holds to read ONE branch's shop monitor: read-only and
 * revocable. Only the token's SHA-256 is stored; the token itself is shown
 * once, when it is made.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property string $token_hash
 * @property string $token_hint
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by_name
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DetailingDisplayToken extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
