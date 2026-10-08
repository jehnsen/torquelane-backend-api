<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\DescribeSession;
use App\Actions\Profile\UpdateProfile;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "My profile": the signed-in user edits their own name, username and
 * password. Any role, either side; nothing here changes access.
 */
final class ProfileController
{
    /**
     * Update my profile
     *
     * First name, last name (the display `name` becomes "first last") and a
     * username (letters, digits, `.`, `-`, `_`; unique in the organization,
     * case-insensitively; 422 when taken). Answers the refreshed session.
     */
    public function update(UpdateProfileRequest $request, UpdateProfile $profile, DescribeSession $describe, TenantManager $tenancy): MeResource
    {
        $user = $profile->update(self::user($request), $request->firstName(), $request->lastName(), $request->username());

        return new MeResource($describe->handle($user, $tenancy->require()));
    }

    /**
     * Change my password
     *
     * `current_password` must match (422 otherwise); the new `password`
     * (with `password_confirmation`) needs 8+ characters and must differ.
     * Other devices' remember-me cookies stop working.
     */
    public function password(ChangePasswordRequest $request, UpdateProfile $profile): Response
    {
        $profile->changePassword(self::user($request), $request->string('current_password')->toString(), $request->string('password')->toString());

        return response()->noContent();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
