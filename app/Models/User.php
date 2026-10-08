<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Access\Role;
use App\Domain\Access\Side;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A staff member or a portal user. Tenant-owned like everything else, which
 * is why authentication finds users through TenantAwareUserProvider (a named
 * system context) before any tenant is known.
 *
 * The CHECKs on the table guarantee: role matches side, and a user is pinned
 * to a customer account exactly when they are on the portal side.
 *
 * @property string $id
 * @property string $organization_id
 * @property Side $side
 * @property string|null $customer_account_id
 * @property Role $role
 * @property string|null $title
 * @property string $name
 * @property string $email
 * @property string $status
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use BelongsToOrganization;

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    public const string ACTIVE = 'active';

    public const string DISABLED = 'disabled';

    protected $attributes = ['status' => self::ACTIVE];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => Side::class,
            'role' => Role::class,
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The people a session may enumerate (port of `scopeAccounts`): staff see
     * everyone in the organization; a portal user only their own account's
     * people, never the organization's staff or a sibling account's.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $accountId = $context->customerAccountId();
        if ($accountId !== null) {
            $query->where($this->qualifyColumn('customer_account_id'), $accountId);
        }
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /**
     * Staff branch restrictions. None = every branch.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('organization_id');
    }

    public function isDisabled(): bool
    {
        return $this->status === self::DISABLED;
    }
}
