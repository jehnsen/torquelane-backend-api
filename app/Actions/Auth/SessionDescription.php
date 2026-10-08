<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Access\Capability;
use App\Domain\Branding\Branding;
use App\Domain\Modules\Module;
use App\Domain\Tenancy\TenantContext;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\User;

/**
 * Everything GET /me reports, fully loaded (no lazy queries at render time).
 */
final readonly class SessionDescription
{
    /**
     * @param  list<Branch>  $branches  allowed branches, by name
     * @param  list<Capability>  $capabilities
     * @param  list<Module>  $activeModules
     * @param  list<Module>  $organizationModules
     * @param  array<string, list<Module>>  $modulesByBranch  active per allowed branch
     */
    public function __construct(
        public User $user,
        public TenantContext $context,
        public Organization $organization,
        public ?CustomerAccount $customerAccount,
        public array $branches,
        public array $capabilities,
        public array $activeModules,
        public array $organizationModules,
        public array $modulesByBranch,
        public Branding $branding,
    ) {}
}
