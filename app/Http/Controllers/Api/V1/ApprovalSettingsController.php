<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounts\SetApprovalOverrides;
use App\Actions\WorkOrders\ApprovalSettingsResolver;
use App\Actions\WorkOrders\SaveApprovalSettings;
use App\Actions\WorkOrders\WorkOrderQueries;
use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Capability;
use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveAccountApprovalSettingsRequest;
use App\Http\Requests\SaveApprovalSettingsRequest;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Approval bands and billing settings, folded organization → branch →
 * customer account. Bands run on the pre-tax subtotal; an unset field
 * inherits and is never zero.
 */
final class ApprovalSettingsController
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly ApprovalSettingsResolver $resolver,
        private readonly WorkOrderQueries $queries,
    ) {}

    /**
     * Show approval settings
     *
     * Staff. The organization's defaults, the selected branch's override
     * (X-Branch-Id) and what that branch runs on.
     */
    public function show(): JsonResponse
    {
        $context = $this->staff();
        $branchId = $context->selectedBranchId;

        return new JsonResponse(['data' => [
            'organization' => $this->resolver->organizationDefaults()->toArray(),
            'branch_id' => $branchId,
            'branch_override' => $branchId === null ? null : (object) $this->queries->branchOverride($branchId),
            'effective' => $this->resolver->forBranch($branchId)->toArray(),
        ]]);
    }

    /**
     * Show an account's approval settings
     *
     * The account's sparse overrides and the settings they resolve to (for
     * staff, in the selected branch). Anyone who can see the account; a
     * portal user's own.
     */
    public function showAccount(CustomerAccount $customerAccount): JsonResponse
    {
        Gate::authorize('view', $customerAccount);

        return $this->account($customerAccount);
    }

    /**
     * Update an account's approval settings
     *
     * `settings:manage`: the account's own Fleet Manager (portal), or staff.
     * Each key sets that band for the account; null goes back to inheriting;
     * an absent key is unchanged. Money in centavos.
     */
    public function updateAccount(SaveAccountApprovalSettingsRequest $request, CustomerAccount $customerAccount, SetApprovalOverrides $set): JsonResponse
    {
        Gate::authorize('manageApprovalSettings', $customerAccount);

        return $this->account($set->handle($customerAccount, $request->changes()));
    }

    private function account(CustomerAccount $account): JsonResponse
    {
        $branchId = $this->tenancy->require()->isStaff() ? $this->tenancy->require()->selectedBranchId : null;

        return new JsonResponse(['data' => [
            'customer_account_id' => $account->id,
            'overrides' => (object) ($account->approval_threshold_overrides ?? []),
            'effective' => $this->resolver->forAccount($account, $branchId)->toArray(),
        ]]);
    }

    /**
     * Update the organization's defaults
     *
     * `organization:manage`.
     */
    public function updateOrganization(SaveApprovalSettingsRequest $request, SaveApprovalSettings $save): JsonResponse
    {
        $this->staff(Capability::OrganizationManage);

        return $this->respond($save->organization($request->values()));
    }

    /**
     * Update a branch's override
     *
     * `settings:manage` in that branch. A null field inherits the organization's.
     */
    public function updateBranch(SaveApprovalSettingsRequest $request, Branch $branch, SaveApprovalSettings $save): JsonResponse
    {
        $context = $this->staff(Capability::SettingsManage);
        if (! $context->branchAllowed($branch->id)) {
            abort(404);
        }

        return $this->respond($save->branch($branch, $request->values()));
    }

    private function staff(?Capability $capability = null): TenantContext
    {
        $context = $this->tenancy->require();
        if (! $context->isStaff()) {
            throw new AuthorizationException('Only staff can do this.');
        }
        if ($capability !== null && ! $context->can($capability)) {
            throw new AuthorizationException(AccessMatrix::denialReason($context->role, $capability));
        }

        return $context;
    }

    private function respond(ApprovalSettings $effective): JsonResponse
    {
        return new JsonResponse(['data' => ['effective' => $effective->toArray()]]);
    }
}
