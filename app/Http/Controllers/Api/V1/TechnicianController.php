<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directory\DirectoryQueries;
use App\Actions\Shop\DeleteShopRecord;
use App\Actions\Shop\SaveTechnician;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\SaveTechnicianRequest;
use App\Http\Resources\TechnicianCollection;
use App\Http\Resources\TechnicianResource;
use App\Models\Technician;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Technicians, branch-owned, tagged with skills (`mechanic`, `detailer`, …).
 * Lists follow the selected branch (X-Branch-Id).
 */
final class TechnicianController
{
    /**
     * List technicians
     */
    public function index(PaginatedRequest $request, DirectoryQueries $queries): TechnicianCollection
    {
        Gate::authorize('viewAny', Technician::class);

        return new TechnicianCollection($queries->technicians($request->perPage()));
    }

    /**
     * Add a technician
     *
     * `settings:manage` and access to `branch_id`.
     */
    public function store(SaveTechnicianRequest $request, SaveTechnician $save): JsonResponse
    {
        Gate::authorize('create', [Technician::class, $request->branchId()]);

        return (new TechnicianResource($save->create($request->technicianAttributes())))->response()->setStatusCode(201);
    }

    /**
     * Show a technician
     */
    public function show(Technician $technician): TechnicianResource
    {
        Gate::authorize('view', $technician);

        return new TechnicianResource($technician);
    }

    /**
     * Update a technician
     */
    public function update(SaveTechnicianRequest $request, Technician $technician, SaveTechnician $save): TechnicianResource
    {
        Gate::authorize('update', $technician);

        return new TechnicianResource($save->update($technician, $request->technicianAttributes()));
    }

    /**
     * Delete a technician
     */
    public function destroy(Technician $technician, DeleteShopRecord $delete): Response
    {
        Gate::authorize('delete', $technician);
        $delete->technician($technician);

        return response()->noContent();
    }
}
