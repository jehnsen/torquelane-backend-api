<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\DescribeSession;
use App\Actions\Invitations\AcceptInvitation;
use App\Domain\Tenancy\ScopeDenial;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Resources\MeResource;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantDenials;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class InvitationAcceptanceController
{
    /**
     * Accept an invitation
     *
     * Creates the account from the emailed invitation token and signs the new
     * user in (when called with a cookie session). Answers with the same body
     * as `GET /me`. Rate-limited.
     *
     * @unauthenticated
     */
    public function __invoke(
        AcceptInvitationRequest $request,
        AcceptInvitation $accept,
        TenantContextResolver $resolver,
        DescribeSession $describe,
    ): JsonResponse {
        $user = $accept->handle($request->string('token')->toString(), $request->string('password')->toString());

        $resolution = $resolver->resolve($user, null);
        if ($resolution->context === null) {
            throw TenantDenials::exceptionFor($resolution->denial ?? ScopeDenial::NoSession, $user->id, 'invitation acceptance');
        }

        $guard = Auth::guard('web');
        if ($request->hasSession()) {
            $guard->login($user);
            $request->session()->regenerate();
        }

        return (new MeResource($describe->handle($user, $resolution->context)))->response()->setStatusCode(201);
    }
}
