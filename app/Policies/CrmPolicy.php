<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The CRM is staff-only and belongs to the `crm` module: side, then the
 * module (403 `module_disabled`), then the capability. `crm:view` reads;
 * `crm:work` keeps notes and tasks, works leads, enrols members and sells or
 * redeems packages; `crm:manage` sets up templates, rules, tiers, plans,
 * segments, campaigns and settings.
 *
 * A CRM record belongs to the organization, not to a branch: another
 * organization's is hidden by the tenant scope (404) before a policy is asked.
 */
final class CrmPolicy extends TenantPolicy
{
    public function view(User $user): Response
    {
        return $this->gate(Capability::CrmView);
    }

    public function work(User $user): Response
    {
        return $this->gate(Capability::CrmWork);
    }

    public function manage(User $user): Response
    {
        return $this->gate(Capability::CrmManage);
    }

    /** Read one customer's record. Scope first: an account the caller cannot reach is 404, whatever their side. */
    public function viewCustomer(User $user, CustomerAccount $account): Response
    {
        return $this->first($this->visible($this->context()->canReachAccount($account->id)), $this->gate(Capability::CrmView));
    }

    /** Keep something on one customer's record. */
    public function workCustomer(User $user, CustomerAccount $account): Response
    {
        return $this->first($this->visible($this->context()->canReachAccount($account->id)), $this->gate(Capability::CrmWork));
    }

    private function gate(Capability $capability): Response
    {
        return $this->first($this->staffOnly(), $this->module(Module::Crm), $this->capability($capability));
    }
}
