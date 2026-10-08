<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Audit\AuditTrail;
use App\Domain\Access\PersonName;
use App\Domain\Tenancy\ScopeDenial;
use App\Exceptions\AccountSuspendedException;
use App\Exceptions\ConflictException;
use App\Exceptions\TenantAccessDeniedException;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a pending invitation into a user, in the named system context
 * "invitation acceptance" (nobody is signed in yet; the token is the only
 * credential). Everything the new user gets comes from the invitation row,
 * never from the request. Re-checks the organization and account at the
 * moment of acceptance: either may have been suspended since the invite.
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(string $token, string $password): User
    {
        return $this->tenancy->system('invitation acceptance', fn (): User => DB::transaction(function () use ($token, $password): User {
            $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->lockForUpdate()->first();
            if ($invitation === null || ! $invitation->isPending()) {
                throw ValidationException::withMessages(['token' => 'This invitation is invalid or has expired. Ask for a new one.']);
            }

            $organization = Organization::query()->findOrFail($invitation->organization_id);
            if ($organization->isSuspended()) {
                throw new TenantAccessDeniedException(ScopeDenial::OrganizationSuspended->message(), ['reason' => ScopeDenial::OrganizationSuspended->value]);
            }
            if ($invitation->customer_account_id !== null
                && CustomerAccount::query()->findOrFail($invitation->customer_account_id)->isSuspended()) {
                throw new AccountSuspendedException(ScopeDenial::AccountSuspended->message(), ['reason' => ScopeDenial::AccountSuspended->value]);
            }
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw new ConflictException('An account with that email already exists.');
            }

            $now = CarbonImmutable::now('UTC');
            $user = new User;
            $user->forceFill([
                'organization_id' => $invitation->organization_id,
                'side' => $invitation->side,
                'role' => $invitation->role,
                'customer_account_id' => $invitation->customer_account_id,
                'name' => $invitation->name,
                'first_name' => PersonName::split($invitation->name)[0],
                'last_name' => PersonName::split($invitation->name)[1],
                'title' => $invitation->title,
                'email' => $invitation->email,
                'password' => $password,
                'email_verified_at' => $now,
                'status' => User::ACTIVE,
            ])->save();

            // Only branches that still exist in the organization; never widen.
            $pins = Branch::query()
                ->where('organization_id', $invitation->organization_id)
                ->whereIn('id', $invitation->branch_ids)
                ->get(['id'])
                ->map(fn (Branch $branch): string => $branch->id)
                ->all();
            if ($invitation->branch_ids !== [] && $pins === []) {
                throw new ConflictException('The branches on this invitation no longer exist. Ask for a new invitation.');
            }
            $user->branches()->attach(array_fill_keys($pins, ['organization_id' => $invitation->organization_id]));

            $before = AuditTrail::snapshot($invitation);
            $invitation->forceFill(['accepted_at' => $now])->save();

            $this->audit->record($invitation, 'accepted', $before, AuditTrail::snapshot($invitation), $user);
            $this->audit->record($user, 'created', null, AuditTrail::snapshot($user) + ['branch_ids' => $pins], $user);

            return $user;
        }));
    }
}
