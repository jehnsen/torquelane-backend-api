<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property string $name
 * @property string|null $role
 * @property string|null $mobile
 * @property string|null $email
 * @property bool $is_primary
 * @property bool $receives_invoices
 * @property bool $receives_reminders
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Contact extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ContactFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'is_primary' => false,
        'receives_invoices' => false,
        'receives_reminders' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'receives_invoices' => 'boolean',
            'receives_reminders' => 'boolean',
        ];
    }

    /**
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
}
