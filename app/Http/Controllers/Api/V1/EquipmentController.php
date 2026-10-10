<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Equipment\EquipmentBranches;
use App\Actions\Equipment\EquipmentQueries;
use App\Actions\Equipment\ManageEquipment;
use App\Domain\Equipment\EquipmentEngine;
use App\Domain\Equipment\EquipmentVocabulary;
use App\Http\Requests\Equipment\EquipmentWriteRequest;
use App\Http\Requests\Equipment\SaveEquipmentRequest;
use App\Http\Resources\EquipmentResource;
use App\Models\DetailingPackage;
use App\Models\Equipment;
use App\Models\EquipmentMaintenanceLog;
use App\Models\EquipmentPackageProfile;
use App\Models\EquipmentUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Equipment monitoring (staff, `equipment` module): a branch's tool register
 * and its monitor. A tool's runtime is never stored: it is derived from the
 * completed jobs of the packages it is mapped to and from its usage log, and
 * every figure the screens show is worked out when the tool is read.
 */
final class EquipmentController
{
    public function __construct(
        private readonly EquipmentBranches $branches,
        private readonly EquipmentQueries $queries,
        private readonly ManageEquipment $equipment,
    ) {}

    /**
     * The equipment board
     *
     * `equipment:view`. Every tool of the branch with its report (service
     * status, forecast, life, reliability, utilisation, health) and the floor
     * in numbers (`summary`).
     */
    public function index(EquipmentWriteRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewIn', [Equipment::class, $branch->id]);
        $board = $this->queries->board($branch);

        return new JsonResponse(['data' => [
            'branch_id' => $branch->id,
            'summary' => $board['summary'],
            'tools' => array_values($board['tools']->map(fn (Equipment $tool): array => (new EquipmentResource($tool, $board['reports'][$tool->id]))->resolve($request))->all()),
        ]]);
    }

    /**
     * The register's lists
     *
     * Categories, statuses, condition ratings, meter types and service kinds
     * with their labels, the starting values each category suggests, and the
     * thresholds the monitor works to.
     */
    public function options(): JsonResponse
    {
        Gate::authorize('viewAny', Equipment::class);

        return new JsonResponse(['data' => [
            'categories' => EquipmentVocabulary::options(EquipmentVocabulary::CATEGORIES),
            'statuses' => EquipmentVocabulary::options(EquipmentVocabulary::STATUSES),
            'conditions' => EquipmentVocabulary::options(EquipmentVocabulary::CONDITIONS),
            'meter_types' => EquipmentVocabulary::options(EquipmentVocabulary::METER_TYPES),
            'maintenance_kinds' => EquipmentVocabulary::options(EquipmentVocabulary::MAINTENANCE_KINDS),
            'category_presets' => EquipmentVocabulary::CATEGORY_PRESETS,
            'thresholds' => [
                'due_soon_ratio' => EquipmentEngine::DUE_SOON_RATIO,
                'forecast_window_days' => EquipmentEngine::FORECAST_WINDOW_DAYS,
                'open_hours_per_day' => EquipmentEngine::OPEN_HOURS_PER_DAY,
            ],
        ]]);
    }

    /**
     * Register a tool
     *
     * `equipment:manage`.
     */
    public function store(SaveEquipmentRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [Equipment::class, $branch->id]);
        $tool = $this->equipment->save($branch, null, $request->tool());

        return (new EquipmentResource($tool, $this->queries->report($tool), true))->response()->setStatusCode(201);
    }

    /**
     * Show a tool
     *
     * Its report, its usage log and its service history.
     */
    public function show(Equipment $equipment): EquipmentResource
    {
        Gate::authorize('viewIn', [Equipment::class, $equipment->branch_id]);

        return new EquipmentResource($equipment, $this->queries->report($equipment), true);
    }

    /**
     * Edit a tool
     *
     * `equipment:manage`. Only what is sent changes.
     */
    public function update(SaveEquipmentRequest $request, Equipment $equipment): EquipmentResource
    {
        Gate::authorize('manageIn', [Equipment::class, $equipment->branch_id]);
        $tool = $this->equipment->save($this->branches->resolve($equipment->branch_id), $equipment, $request->tool());

        return new EquipmentResource($tool, $this->queries->report($tool), true);
    }

    /**
     * Change a tool's status
     *
     * `equipment:manage`.
     */
    public function status(EquipmentWriteRequest $request, Equipment $equipment): EquipmentResource
    {
        Gate::authorize('manageIn', [Equipment::class, $equipment->branch_id]);
        $status = $request->status() ?? throw ValidationException::withMessages(['status' => 'Say which status.']);
        $tool = $this->equipment->setStatus($equipment, $status);

        return new EquipmentResource($tool, $this->queries->report($tool), true);
    }

    /**
     * Delete a tool
     *
     * `equipment:manage`. Only one with nothing logged against it and no
     * package mapped; any other is 409: retire it instead.
     */
    public function destroy(Equipment $equipment): Response
    {
        Gate::authorize('manageIn', [Equipment::class, $equipment->branch_id]);
        $this->equipment->delete($equipment);

        return response()->noContent();
    }

    /**
     * Log usage
     *
     * `equipment:manage`. Runtime hours and/or job cycles the booking sheet
     * never saw. Completed bookings accrue by themselves through the package
     * profiles: logging one here too would count it twice.
     */
    public function logUsage(EquipmentWriteRequest $request, Equipment $equipment): JsonResponse
    {
        Gate::authorize('manageIn', [Equipment::class, $equipment->branch_id]);
        $this->equipment->logUsage($equipment, $request->usage());

        return (new EquipmentResource($equipment->refresh(), $this->queries->report($equipment), true))->response()->setStatusCode(201);
    }

    /**
     * Remove a usage entry
     */
    public function deleteUsage(Equipment $equipment, EquipmentUsageLog $usageLog): Response
    {
        Gate::authorize('manageIn', [Equipment::class, $equipment->branch_id]);
        $this->equipment->deleteUsage($usageLog);

        return response()->noContent();
    }

    /**
     * Log a service
     *
     * `equipment:manage`. Unless `resets_service` is false (a clean
     * inspection), it restarts every service interval from `performed_at`.
     */
    public function logService(EquipmentWriteRequest $request, Equipment $equipment): JsonResponse
    {
        Gate::authorize('manageIn', [Equipment::class, $equipment->branch_id]);
        $this->equipment->logService($equipment, $request->service());

        return (new EquipmentResource($equipment->refresh(), $this->queries->report($equipment), true))->response()->setStatusCode(201);
    }

    /**
     * Remove a service entry
     */
    public function deleteService(Equipment $equipment, EquipmentMaintenanceLog $maintenanceLog): Response
    {
        Gate::authorize('manageIn', [Equipment::class, $equipment->branch_id]);
        $this->equipment->deleteService($maintenanceLog);

        return response()->noContent();
    }

    /**
     * Package profiles
     *
     * `equipment:view`. Which tools each detailing package puts hours on,
     * and how many per completed job.
     */
    public function profiles(EquipmentWriteRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewIn', [Equipment::class, $branch->id]);

        return new JsonResponse(['data' => array_values($this->queries->profiles($branch)->map(fn (EquipmentPackageProfile $profile): array => [
            'id' => $profile->id,
            'package_id' => $profile->package_id,
            'package_name' => $profile->package->name,
            'equipment_id' => $profile->equipment_id,
            'equipment_name' => $profile->equipment->name,
            'hours_per_job' => (string) $profile->hours_per_job,
        ])->all())]);
    }

    /**
     * Set a package's profile
     *
     * `equipment:manage`. The whole list for one package: `lines` of
     * `equipment_id` and `hours_per_job`.
     */
    public function setProfile(EquipmentWriteRequest $request, DetailingPackage $detailingPackage): Response
    {
        Gate::authorize('manageIn', [Equipment::class, $detailingPackage->branch_id]);
        $this->equipment->setProfile($detailingPackage, $request->lines());

        return response()->noContent();
    }
}
