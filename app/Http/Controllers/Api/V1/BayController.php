<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directory\DirectoryQueries;
use App\Actions\Shop\DeleteShopRecord;
use App\Actions\Shop\SaveBay;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\SaveBayRequest;
use App\Http\Resources\BayCollection;
use App\Http\Resources\BayResource;
use App\Models\Bay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Service bays, branch-owned. Lists follow the selected branch (X-Branch-Id).
 */
final class BayController
{
    /**
     * List bays
     */
    public function index(PaginatedRequest $request, DirectoryQueries $queries): BayCollection
    {
        Gate::authorize('viewAny', Bay::class);

        return new BayCollection($queries->bays($request->perPage()));
    }

    /**
     * Add a bay
     *
     * `settings:manage` and access to `branch_id`.
     */
    public function store(SaveBayRequest $request, SaveBay $save): JsonResponse
    {
        Gate::authorize('create', [Bay::class, $request->branchId()]);

        return (new BayResource($save->create($request->bayAttributes())))->response()->setStatusCode(201);
    }

    /**
     * Show a bay
     */
    public function show(Bay $bay): BayResource
    {
        Gate::authorize('view', $bay);

        return new BayResource($bay);
    }

    /**
     * Update a bay
     */
    public function update(SaveBayRequest $request, Bay $bay, SaveBay $save): BayResource
    {
        Gate::authorize('update', $bay);

        return new BayResource($save->update($bay, $request->bayAttributes()));
    }

    /**
     * Delete a bay
     *
     * A technician's home bay is a 409.
     */
    public function destroy(Bay $bay, DeleteShopRecord $delete): Response
    {
        Gate::authorize('delete', $bay);
        $delete->bay($bay);

        return response()->noContent();
    }
}
