<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Audit\AuditTrail;
use App\Actions\Users\BranchContainment;
use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Role;
use App\Domain\Access\Side;
use App\Domain\Tenancy\TenantContext;
use App\Exceptions\AccountSuspendedException;
use App\Models\CustomerAccount;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites a staff member or a portal user by email. Port of the rules in
 * ../web/app/api/admin/users/route.ts, decided from the caller's tenant
 * context and never from what the request claims about itself:
 *
 *  - only roles with access:manage may invite (InvitationPolicy);
 *  - portal roles must be pinned to a customer account in the caller's
 *    organization, which must be active (a suspended account takes no new
 *    people; 403 account_suspended);
 *  - staff roles must not be pinned to an account;
 *  - a portal-side inviter could only add portal people to their own account
 *    (no portal role holds access:manage today; the rule is kept anyway);
 *  - an account with that email must not already exist.
 *
 * Added in the API: no escalation (the invited role's grants must be a subset
 * of the inviter's), and branch containment for branch-pinned inviters.
 * Re-inviting an email with a pending invitation revokes the old one.
 */
final class CreateInvitation
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{email: string, name: string, role: string, title?: string|null, customer_account_id?: string|null, branch_ids?: list<string>}  $input
     */
    public function handle(array $input): Invitation
    {
        $context = $this->tenancy->require();
        $role = Role::from($input['role']);
        $email = mb_strtolower(trim($input['email']));

        if (! AccessMatrix::canGrant($context->role, $role)) {
            throw new AuthorizationException('You cannot invite someone to a role with permissions you do not have.');
        }

        [$accountId, $branchIds] = $role->isStaff()
            ? $this->staffPlacement($context, $input)
            : $this->portalPlacement($context, $input);

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($context, $input, $role, $email, $accountId, $branchIds, $token): Invitation {
            $exists = $this->tenancy->system('invitation email uniqueness', fn (): bool => User::query()->where('email', $email)->exists());
            if ($exists) {
                throw ValidationException::withMessages(['email' => 'An account with that email already exists.']);
            }

            foreach (Invitation::query()->pending()->where('email', $email)->lockForUpdate()->get() as $previous) {
                $before = AuditTrail::snapshot($previous);
                $previous->forceFill(['revoked_at' => CarbonImmutable::now('UTC')])->save();
                $this->audit->record($previous, 'revoked', $before, AuditTrail::snapshot($previous));
            }

            $invitation = new Invitation;
            $invitation->forceFill([
                'email' => $email,
                'name' => trim($input['name']),
                'side' => $role->side(),
                'role' => $role,
                'title' => $input['title'] ?? null,
                'customer_account_id' => $accountId,
                'branch_ids' => $branchIds,
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $context->userId,
                'expires_at' => CarbonImmutable::now('UTC')->addDays(Invitation::EXPIRES_AFTER_DAYS),
            ])->save();
            $this->audit->record($invitation, 'created', null, AuditTrail::snapshot($invitation));

            return $invitation;
        });

        $organization = Organization::query()->findOrFail($context->organizationId());
        $inviter = User::query()->findOrFail($context->userId);
        Notification::route('mail', $email)->notify(
            new InvitationNotification($organization->name, $inviter->name, $token, $invitation->expires_at),
        );

        return $invitation;
    }

    /**
     * @param  array{customer_account_id?: string|null, branch_ids?: list<string>}  $input
     * @return array{0: null, 1: list<string>}
     */
    private function staffPlacement(TenantContext $context, array $input): array
    {
        if (! $context->isStaff()) {
            throw new AuthorizationException('Only staff can add staff accounts.');
        }
        if (($input['customer_account_id'] ?? null) !== null) {
            throw ValidationException::withMessages(['customer_account_id' => 'Staff roles are not pinned to a customer account.']);
        }

        return [null, BranchContainment::pins($context, $input['branch_ids'] ?? [])];
    }

    /**
     * @param  array{customer_account_id?: string|null, branch_ids?: list<string>}  $input
     * @return array{0: string, 1: list<string>}
     */
    private function portalPlacement(TenantContext $context, array $input): array
    {
        $accountId = $input['customer_account_id'] ?? null;
        if ($accountId === null) {
            throw ValidationException::withMessages(['customer_account_id' => 'Choose which customer account this person belongs to.']);
        }
        if (($input['branch_ids'] ?? []) !== []) {
            throw ValidationException::withMessages(['branch_ids' => 'Portal users are not assigned to branches.']);
        }
        if ($context->isPortal() && $accountId !== $context->customerAccountId()) {
            throw new AuthorizationException('You can only add people to your own account.');
        }

        $account = CustomerAccount::query()->find($accountId);
        if ($account === null) {
            throw ValidationException::withMessages(['customer_account_id' => "That customer account doesn't belong to your organization."]);
        }
        if ($account->isSuspended()) {
            throw new AccountSuspendedException('That customer account is suspended; a new portal user there would resolve to no scope.');
        }

        return [$account->id, []];
    }
}
