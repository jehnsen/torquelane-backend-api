<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directory\DirectoryQueries;
use App\Actions\Users\UpdateUser;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The personnel directory: staff see everyone in the organization, a portal
 * user only their own account's people. New people arrive by invitation.
 */
final class UserController
{
    /**
     * List people
     */
    public function index(PaginatedRequest $request, DirectoryQueries $queries): UserCollection
    {
        Gate::authorize('viewAny', User::class);

        return new UserCollection($queries->users($request->perPage()));
    }

    /**
     * Show a person
     */
    public function show(User $user, DirectoryQueries $queries): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($queries->user($user));
    }

    /**
     * Change someone's access
     *
     * `access:manage`. Not yourself, and never to or from a role whose grants
     * you do not hold. `branch_ids: []` means every branch, which only an
     * unrestricted manager can grant. Roles never move between staff and
     * portal.
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUser $update, DirectoryQueries $queries): UserResource
    {
        Gate::authorize('update', $user);

        return new UserResource($queries->user($update->handle($user, $request->userAttributes(), $request->branchIds())));
    }
}
