<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Audit\AuditTrail;
use App\Domain\Access\PersonName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own profile (../web lib/auth.ts updateProfile and
 * changePassword). Role, side, account and branches are never touched here:
 * those are the access screen's, under `access:manage`.
 */
final class UpdateProfile
{
    public function __construct(private readonly AuditTrail $audit) {}

    /** First and last name (the display name follows) and the username. */
    public function update(User $user, string $firstName, string $lastName, string $username): User
    {
        return DB::transaction(function () use ($user, $firstName, $lastName, $username): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $taken = User::query()
                ->where('organization_id', $locked->organization_id)
                ->whereKeyNot($locked->id)
                ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['username' => 'That username is already taken.']);
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => PersonName::join($firstName, $lastName),
                'username' => $username,
            ])->save();
            $this->audit->record($locked, 'profile_updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * A new password, once the current one is proven. The remember token is
     * rotated, so a "remember me" cookie on another device stops working.
     *
     * @return string the new password hash, so the caller can keep its own session
     */
    public function changePassword(User $user, string $current, string $new): string
    {
        return DB::transaction(function () use ($user, $current, $new): string {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($current, $locked->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => 'Your current password isn\'t correct.']);
            }

            $locked->forceFill(['password' => Hash::make($new)])->setRememberToken(bin2hex(random_bytes(30)));
            $locked->save();
            $this->audit->record($locked, 'password_changed', null, null);

            return $locked->getAuthPassword();
        });
    }
}
