<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\ConsentDecision;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only consent decision (the table's triggers refuse UPDATE and
 * DELETE). Never `save()` a loaded one.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property string|null $contact_id
 * @property ConsentPurpose $purpose
 * @property bool $granted
 * @property ConsentChannel $channel
 * @property CarbonImmutable $captured_at
 * @property string|null $captured_by
 * @property string|null $evidence
 * @property CarbonImmutable $created_at
 */
final class Consent extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'channel' => ConsentChannel::class,
            'granted' => 'boolean',
            'captured_at' => 'immutable_datetime',
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

    public function toDecision(): ConsentDecision
    {
        return new ConsentDecision($this->id, $this->purpose, $this->granted, $this->captured_at, $this->contact_id);
    }
}
