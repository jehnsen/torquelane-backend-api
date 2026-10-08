<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every tenant-owned model uses this (R5; tests/Arch enforces it on every
 * class in App\Models except an explicit allowlist).
 *
 *  - Reads: the OrganizationScope global scope.
 *  - Creates: `organization_id` is filled from the tenant context when not
 *    given; with neither, the create throws.
 *  - Saves: writing a row into an organization other than the current
 *    tenant's throws, outside a named system context.
 *
 * Saves and deletes of an already-loaded model are not scoped by Eloquent
 * (they key on the primary key alone), which is why the save guard exists.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        // `saving` fires before `creating`, so filling and checking happen here,
        // in that order, for inserts and updates alike.
        static::saving(function (Model $model): void {
            $tenancy = app(TenantManager::class);
            $context = $tenancy->context();
            $organizationId = $model->getAttributes()['organization_id'] ?? null;

            if ($organizationId === null) {
                $context ?? throw TenancyViolation::missingContext('saving '.$model::class);
                $model->setAttribute('organization_id', $context->organizationId());

                return;
            }

            if (! $tenancy->inSystemContext() && $context !== null && $organizationId !== $context->organizationId()) {
                throw TenancyViolation::crossTenantWrite($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
