<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Tenancy\AccountStanding;
use App\Exceptions\AccountSuspendedException;
use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Staff reach every account in the organization, suspended ones included
 * (collections, history). A portal user reaches only their own; any other id
 * is 404.
 */
final class CustomerAccountPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, CustomerAccount $account): Response
    {
        return $this->first($this->visible($this->context()->canReachAccount($account->id)));
    }

    public function create(User $user): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->capability(Capability::CustomerManage),
        );
    }

    /** Also covers its contacts and consent decisions. */
    public function update(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->capability(Capability::CustomerManage),
        );
    }

    /**
     * The account's own approval bands (../web: a client's Fleet Manager
     * sets them from Settings; the provider's staff may too): scope, then
     * `settings:manage`. A portal user reaches only their own account.
     */
    public function manageApprovalSettings(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->capability(Capability::SettingsManage),
        );
    }

    /** Suspend or reactivate: a credit decision, staff with settings:manage. */
    public function setStatus(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->capability(Capability::SettingsManage),
        );
    }

    /**
     * The gate every "start new work for this account" path must pass:
     * portal invitations now; work orders, quotes, bookings and sales on
     * account in later phases. A suspended account takes no new work (403
     * account_suspended), though staff can still read it.
     */
    public function createWorkFor(User $user, CustomerAccount $account): Response
    {
        $hidden = $this->visible($this->context()->canReachAccount($account->id));
        if ($hidden !== null) {
            return $hidden;
        }
        if (! AccountStanding::acceptsNewWork($account->status)) {
            throw new AccountSuspendedException('This customer account is suspended; it cannot take new work until it is reactivated.');
        }

        return Response::allow();
    }

    /** Balance and statement of account (Phase 7): staff, or the account's own portal users, with `billing:view`. */
    public function viewBilling(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->capability(Capability::BillingView),
        );
    }
}
