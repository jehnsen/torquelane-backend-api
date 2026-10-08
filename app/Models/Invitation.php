<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Access\Role;
use App\Domain\Access\Side;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property string $name
 * @property Side $side
 * @property Role $role
 * @property string|null $title
 * @property string|null $customer_account_id
 * @property list<string> $branch_ids
 * @property string $token_hash
 * @property string $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Invitation extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const int EXPIRES_AFTER_DAYS = 7;

    protected $hidden = ['token_hash'];

    protected $attributes = ['branch_ids' => '[]'];

    protected function casts(): array
    {
        return [
            'side' => Side::class,
            'role' => Role::class,
            'branch_ids' => 'array',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
