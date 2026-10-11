<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Database\Cell;
use App\Domain\Access\Role;
use App\Domain\Modules\Module;
use App\Models\OrganizationModule;
use App\Models\User;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Closure;

/**
 * Scheduled CRM work has no session. It runs once per organization that has
 * the `crm` module on, AS that organization (its first active provider admin),
 * so every read stays inside the organization's tenant scope exactly as it
 * would for a person, instead of running unscoped and filtering by hand.
 */
final class CrmContexts
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly TenantContextResolver $resolver,
    ) {}

    /**
     * @return list<string>
     */
    public function organizations(): array
    {
        return $this->tenancy->system('crm schedule', fn (): array => array_values(OrganizationModule::query()
            ->where('module', Module::Crm->value)->where('enabled', true)->orderBy('organization_id')
            ->pluck('organization_id')->map(fn (mixed $id): string => Cell::string($id))->all()));
    }

    /**
     * Run `$callback` as the organization; null when it has nobody to act as.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    public function as(string $organizationId, Closure $callback): mixed
    {
        $actor = $this->tenancy->system('crm schedule', fn (): ?User => User::query()
            ->where('organization_id', $organizationId)->where('role', Role::ProviderAdmin->value)->where('status', 'active')
            ->orderBy('id')->first());
        $context = $actor === null ? null : $this->resolver->resolve($actor, null)->context;

        return $context === null ? null : $this->tenancy->actingAs($context, $callback);
    }

    /**
     * Run `$callback` once as each organization with the module on.
     *
     * @param  Closure(string): void  $callback
     */
    public function each(Closure $callback): void
    {
        foreach ($this->organizations() as $organizationId) {
            $this->as($organizationId, fn () => $callback($organizationId));
        }
    }
}
