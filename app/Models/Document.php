<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Documents\DocumentFacts;
use App\Domain\Documents\DocumentKind;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property string|null $vehicle_id
 * @property string|null $work_order_id the order it is attached to (same account)
 * @property DocumentKind $kind
 * @property string $name
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property string|null $storage_path
 * @property CarbonImmutable|null $expires_on
 * @property string|null $reference_number
 * @property CarbonImmutable|null $issued_on
 * @property string|null $issuing_body
 * @property string|null $notes
 * @property string|null $uploaded_by
 * @property string|null $uploaded_by_name
 * @property CarbonImmutable $uploaded_on
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Document extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'size_bytes' => 'integer',
            'expires_on' => 'immutable_date',
            'issued_on' => 'immutable_date',
            'uploaded_on' => 'immutable_date',
        ];
    }

    /**
     * Staff: every document. Portal: documents filed under their account
     * only, so after a vehicle changes hands the new owner does not see the
     * previous owner's papers (and the previous owner keeps their own).
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

    public function facts(): DocumentFacts
    {
        return new DocumentFacts($this->id, $this->name, $this->kind, $this->vehicle_id, $this->expires_on?->toDateString());
    }

    public function hasFile(): bool
    {
        return $this->storage_path !== null;
    }
}
