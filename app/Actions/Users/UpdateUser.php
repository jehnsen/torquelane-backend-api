<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Audit\AuditTrail;
use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Role;
use App\Models\Branch;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes someone else's role, title, name, status or (staff) branches.
 * UserPolicy::update has already refused self-edits and targets whose role
 * outranks the caller. Here: a new role must stay on the same side and be
 * grantable; branch pins obey BranchContainment.
 */
final class UpdateUser
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by UpdateUserRequest (name, title, role, status)
     * @param  list<string>|null  $branchIds  null when not being changed
     */
    public function handle(User $target, array $attributes, ?array $branchIds): User
    {
        $context = $this->tenancy->require();

        return DB::transaction(function () use ($context, $target, $attributes, $branchIds): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $currentPins = $this->pins($locked);

            if (is_string($attributes['role'] ?? null)) {
                $role = Role::from($attributes['role']);
                if ($role->side() !== $locked->side) {
                    throw ValidationException::withMessages(['role' => 'A role cannot move someone between staff and portal. Invite them again instead.']);
                }
                if (! AccessMatrix::canGrant($context->role, $role)) {
                    throw new AuthorizationException('You cannot grant a role with permissions you do not have.');
                }
            }

            if ($locked->side->value === 'staff') {
                BranchContainment::assertCovers($context, $currentPins);
            } elseif ($branchIds !== null && $branchIds !== []) {
                throw ValidationException::withMessages(['branch_ids' => 'Portal users are not assigned to branches.']);
            }

            $before = AuditTrail::snapshot($locked) + ['branch_ids' => $currentPins];

            $locked->forceFill($attributes)->save();
            if ($branchIds !== null && $locked->side->value === 'staff') {
                $pins = BranchContainment::pins($context, $branchIds);
                $locked->branches()->sync(array_fill_keys($pins, ['organization_id' => $locked->organization_id]));
            }

            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked) + ['branch_ids' => $this->pins($locked)]);

            return $locked;
        });
    }

    /**
     * @return list<string>
     */
    private function pins(User $user): array
    {
        $ids = $user->branches()->get(['branches.id'])->map(fn (Branch $branch): string => $branch->id)->all();
        sort($ids);

        return $ids;
    }
}
