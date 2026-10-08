<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fleet\FleetQueries;
use App\Actions\Fleet\SaveServiceTask;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\SaveServiceTaskRequest;
use App\Http\Resources\ServiceTaskCollection;
use App\Http\Resources\ServiceTaskResource;
use App\Models\ServiceTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The PMS catalogue, organization-wide. Needs repair_pms.
 */
final class ServiceTaskController
{
    /**
     * List service tasks
     *
     * In catalogue order (ties in urgency keep it), inactive ones included.
     */
    public function index(PaginatedRequest $request, FleetQueries $fleet): ServiceTaskCollection
    {
        Gate::authorize('viewAny', ServiceTask::class);

        return new ServiceTaskCollection($fleet->taskPage($request->perPage()));
    }

    /**
     * Add a service task
     *
     * Staff with `settings:manage`.
     */
    public function store(SaveServiceTaskRequest $request, SaveServiceTask $save): JsonResponse
    {
        Gate::authorize('create', ServiceTask::class);

        return (new ServiceTaskResource($save->create($request->taskAttributes())))->response()->setStatusCode(201);
    }

    /**
     * Show a service task
     */
    public function show(ServiceTask $serviceTask): ServiceTaskResource
    {
        Gate::authorize('view', $serviceTask);

        return new ServiceTaskResource($serviceTask);
    }

    /**
     * Update a service task
     *
     * Editing an interval never changes any vehicle's recorded service
     * history; due dates move because they are derived.
     */
    public function update(SaveServiceTaskRequest $request, ServiceTask $serviceTask, SaveServiceTask $save): ServiceTaskResource
    {
        Gate::authorize('update', $serviceTask);

        return new ServiceTaskResource($save->update($serviceTask, $request->taskAttributes()));
    }

    /**
     * Delete a service task
     *
     * Only one no vehicle has history for (409 otherwise; deactivate it).
     */
    public function destroy(ServiceTask $serviceTask, SaveServiceTask $save): Response
    {
        Gate::authorize('delete', $serviceTask);
        $save->delete($serviceTask);

        return response()->noContent();
    }
}
