<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directory\DirectoryQueries;
use App\Actions\Organization\UpdateOrganization;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use Illuminate\Support\Facades\Gate;

/**
 * The caller's own organization. There is no id in the path: the
 * organization is always the session's.
 */
final class OrganizationController
{
    /**
     * Organization profile
     */
    public function show(DirectoryQueries $queries): OrganizationResource
    {
        $organization = $queries->organization();
        Gate::authorize('view', $organization);

        return new OrganizationResource($organization);
    }

    /**
     * Update the organization profile
     *
     * Needs `organization:manage`.
     */
    public function update(UpdateOrganizationRequest $request, DirectoryQueries $queries, UpdateOrganization $update): OrganizationResource
    {
        $organization = $queries->organization();
        Gate::authorize('update', $organization);

        return new OrganizationResource($update->handle($organization, $request->validated()));
    }
}
