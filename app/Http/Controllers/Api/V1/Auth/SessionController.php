<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\DescribeSession;
use App\Actions\Auth\Login;
use App\Exceptions\AccountSuspendedException;
use App\Exceptions\TenantAccessDeniedException;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class SessionController
{
    /**
     * Sign in
     *
     * Cookie-session login for the SPA: call `GET /sanctum/csrf-cookie` first,
     * then post with the `X-XSRF-TOKEN` header, from an allowed origin. Answers
     * with the same body as `GET /me`. A session that would resolve to no
     * tenant scope (a suspended account's portal user, a disabled user) is
     * refused with its reason. Rate-limited per email and per IP.
     *
     * @unauthenticated
     *
     * @throws AccountSuspendedException a portal user of a suspended account (403 account_suspended)
     * @throws TenantAccessDeniedException any other session with no tenant scope (403, details.reason)
     */
    public function login(LoginRequest $request, Login $login, DescribeSession $describe): MeResource
    {
        [$user, $context] = $login->handle(
            $request,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        return new MeResource($describe->handle($user, $context));
    }

    /**
     * Sign out
     *
     * Ends the cookie session. Works for any signed-in user, including one
     * whose account was suspended mid-session.
     */
    public function logout(Request $request): Response
    {
        $guard = Auth::guard('web');
        if ($request->hasSession()) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /**
     * Who am I
     *
     * The signed-in user, their organization, side, branches (allowed and
     * selected), customer account (portal), resolved capabilities, active
     * modules and branding. The frontend gates its UI from this.
     *
     * @throws AccountSuspendedException a portal user of a suspended account (403 account_suspended)
     * @throws TenantAccessDeniedException no tenant scope, or an X-Branch-Id the user may not work in (403, details.reason)
     */
    public function me(Request $request, DescribeSession $describe, TenantManager $tenancy): MeResource
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return new MeResource($describe->handle($user, $tenancy->require()));
    }
}
