<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directory\DirectoryQueries;
use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\StoreInvitationRequest;
use App\Http\Resources\InvitationCollection;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class InvitationController
{
    /**
     * List pending invitations
     *
     * `access:manage`.
     */
    public function index(PaginatedRequest $request, DirectoryQueries $queries): InvitationCollection
    {
        Gate::authorize('viewAny', Invitation::class);

        return new InvitationCollection($queries->invitations($request->perPage()));
    }

    /**
     * Invite someone
     *
     * `access:manage`. Portal roles must name an active `customer_account_id`;
     * staff roles must not name one. You can only invite to roles whose grants
     * you hold, and a branch-pinned manager only into their own branches.
     * The invitation link is emailed; the token is never returned.
     */
    public function store(StoreInvitationRequest $request, CreateInvitation $create): JsonResponse
    {
        Gate::authorize('create', Invitation::class);

        return (new InvitationResource($create->handle($request->invitation())))->response()->setStatusCode(201);
    }

    /**
     * Revoke an invitation
     */
    public function destroy(Invitation $invitation, RevokeInvitation $revoke): InvitationResource
    {
        Gate::authorize('delete', $invitation);

        return new InvitationResource($revoke->handle($invitation));
    }
}
