<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Capability;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The personnel directory (port of `scopeAccounts`): staff see everyone in
 * the organization; a portal user only their own account's people.
 */
final class UserPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, User $target): Response
    {
        return $this->first($this->visible($this->sees($target)));
    }

    /**
     * Role, title, status and branches of someone else. Never yourself (no
     * self-escalation, no locking yourself out), never someone whose role
     * carries grants you do not hold.
     */
    public function update(User $user, User $target): Response
    {
        return $this->first(
            $this->visible($this->sees($target)),
            $this->capability(Capability::AccessManage),
            $target->id === $user->id ? Response::deny('You cannot change your own access.') : null,
            AccessMatrix::canGrant($this->context()->role, $target->role)
                ? null
                : Response::deny('You cannot manage someone whose role carries permissions you do not have.'),
        );
    }

    private function sees(User $target): bool
    {
        $context = $this->context();

        return $context->isStaff() || $target->customer_account_id === $context->customerAccountId();
    }
}
