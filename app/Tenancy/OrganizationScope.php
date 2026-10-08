<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The global scope every tenant model carries (BelongsToOrganization):
 * `organization_id = <current tenant>`, or a TenancyViolation when there is no
 * tenant and no named system context.
 *
 * Organization only. Portal ownership (one customer account) and staff branch
 * restrictions are applied per query path through each model's
 * `visibleTo()` scope and the policies, never by this scope alone.
 *
 * @implements Scope<Model>
 */
final class OrganizationScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(TenantManager::class);

        if ($tenancy->inSystemContext()) {
            return;
        }

        $context = $tenancy->context() ?? throw TenancyViolation::missingContext('a query on '.$model::class);

        $builder->where($model->qualifyColumn('organization_id'), $context->organizationId());
    }
}
