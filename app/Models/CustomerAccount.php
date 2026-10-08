<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Casts\MoneyCast;
use App\Domain\Branding\BrandMark;
use App\Domain\Tenancy\AccountStanding;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The frontend's "fleet client", generalised to walk-ins and members.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $account_type
 * @property string $display_name
 * @property string|null $registered_name
 * @property string|null $tin
 * @property string|null $address
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $nickname
 * @property string|null $mobile
 * @property string|null $email
 * @property CarbonImmutable|null $birthday
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property int $payment_terms_days
 * @property Money|null $credit_limit_cents
 * @property array<string, int|string>|null $approval_threshold_overrides
 * @property list<string> $tags
 * @property string|null $source
 * @property string|null $notes
 * @property string|null $logo_url
 * @property string|null $brand_color
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CustomerAccount extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CustomerAccountFactory> */
    use HasFactory, HasUlids;

    public const string COMPANY = 'company';

    public const string INDIVIDUAL = 'individual';

    protected $attributes = [
        'payment_terms_days' => 0,
        'tags' => '[]',
        'status' => AccountStanding::ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'immutable_date',
            'payment_terms_days' => 'integer',
            'credit_limit_cents' => MoneyCast::class,
            'approval_threshold_overrides' => JsonObject::class,
            'tags' => 'array',
        ];
    }

    /**
     * Staff: every account in the organization, suspended ones included.
     * Portal: exactly their own.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $accountId = $context->customerAccountId();
        if ($accountId !== null) {
            $query->where($this->qualifyColumn('id'), $accountId);
        }
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasMany<Consent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === AccountStanding::SUSPENDED;
    }

    public function brandMark(): BrandMark
    {
        return new BrandMark($this->display_name, $this->logo_url, $this->brand_color);
    }
}
