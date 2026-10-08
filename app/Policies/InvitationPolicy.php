<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Capability;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Port of ../web/app/api/admin/users/route.ts: only roles holding
 * access:manage may invite. The per-invitation rules (portal roles pinned to
 * an account, staff roles not; no escalation; branch containment) are
 * enforced by CreateInvitation.
 */
final class InvitationPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->first($this->capability(Capability::AccessManage));
    }

    public function create(User $user): Response
    {
        return $this->first($this->capability(Capability::AccessManage));
    }

    public function delete(User $user, Invitation $invitation): Response
    {
        $context = $this->context();

        return $this->first(
            $this->visible($context->isStaff() || $invitation->customer_account_id === $context->customerAccountId()),
            $this->capability(Capability::AccessManage),
            AccessMatrix::canGrant($context->role, $invitation->role)
                ? null
                : Response::deny('You cannot revoke an invitation for a role you could not grant.'),
        );
    }
}
