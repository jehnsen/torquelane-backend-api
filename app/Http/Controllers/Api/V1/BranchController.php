<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Branches\CreateBranch;
use App\Actions\Branches\DeleteBranch;
use App\Actions\Branches\UpdateBranch;
use App\Actions\Directory\DirectoryQueries;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\SaveBranchRequest;
use App\Http\Resources\BranchCollection;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Staff only. A staff member pinned to some branches sees only those.
 */
final class BranchController
{
    /**
     * List branches
     */
    public function index(PaginatedRequest $request, DirectoryQueries $queries): BranchCollection
    {
        Gate::authorize('viewAny', Branch::class);

        return new BranchCollection($queries->branches($request->perPage()));
    }

    /**
     * Open a branch
     *
     * Needs `organization:manage`. Every module starts switched off.
     */
    public function store(SaveBranchRequest $request, CreateBranch $create): JsonResponse
    {
        Gate::authorize('create', Branch::class);

        return (new BranchResource($create->handle($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Show a branch
     */
    public function show(Branch $branch): BranchResource
    {
        Gate::authorize('view', $branch);

        return new BranchResource($branch);
    }

    /**
     * Update a branch
     *
     * Needs `settings:manage` and access to the branch.
     */
    public function update(SaveBranchRequest $request, Branch $branch, UpdateBranch $update): BranchResource
    {
        Gate::authorize('update', $branch);

        return new BranchResource($update->handle($branch, $request->validated()));
    }

    /**
     * Delete an empty branch
     *
     * Needs `organization:manage`. A branch with bays, technicians, pinned
     * staff or document series is a 409: mark it inactive instead.
     */
    public function destroy(Branch $branch, DeleteBranch $delete): Response
    {
        Gate::authorize('delete', $branch);
        $delete->handle($branch);

        return response()->noContent();
    }
}
