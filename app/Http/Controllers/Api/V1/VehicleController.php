<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fleet\CreateVehicle;
use App\Actions\Fleet\FleetQueries;
use App\Actions\Fleet\TransferVehicle;
use App\Actions\Fleet\UpdateVehicle;
use App\Http\Requests\ListVehiclesRequest;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\SaveVehicleRequest;
use App\Http\Requests\TransferVehicleRequest;
use App\Http\Resources\VehicleCollection;
use App\Http\Resources\VehicleHealthResource;
use App\Http\Resources\VehicleOwnershipCollection;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Vehicles carry their computed state (odometer and daily rate from
 * readings, PMS summary, compliance), so the frontend renders rather than
 * computes. Staff see every vehicle; a portal user the ones their account
 * owns now.
 */
final class VehicleController
{
    /**
     * List vehicles
     *
     * `q` matches a plate or VIN on its normalised form ("abc-1234" finds
     * "ABC 1234"), exactly or as a plate prefix.
     */
    public function index(ListVehiclesRequest $request, FleetQueries $fleet): VehicleCollection
    {
        Gate::authorize('viewAny', Vehicle::class);

        return new VehicleCollection($fleet->vehiclePage($request->filters(), $request->boolean('include_archived'), $request->perPage()));
    }

    /**
     * Register a vehicle
     *
     * `vehicle:manage`. Staff name the `customer_account_id`; a portal user
     * registers to their own account. Takes the first odometer reading and
     * any known service history. A suspended account takes no new vehicles
     * (403 account_suspended).
     */
    public function store(SaveVehicleRequest $request, FleetQueries $fleet, CreateVehicle $create): JsonResponse
    {
        $account = $fleet->account($request->filled('customer_account_id') ? $request->string('customer_account_id')->lower()->toString() : null);
        Gate::authorize('create', [Vehicle::class, $account]);
        Gate::authorize('createWorkFor', $account);

        $vehicle = $create->handle($account, $request->vehicleAttributes(), $request->odometer(), $request->serviceHistory());

        return (new VehicleResource($fleet->view($vehicle)))->response()->setStatusCode(201);
    }

    /**
     * Show a vehicle
     */
    public function show(Vehicle $vehicle, FleetQueries $fleet): VehicleResource
    {
        Gate::authorize('view', $vehicle);

        return new VehicleResource($fleet->view($vehicle));
    }

    /**
     * Update a vehicle
     *
     * `vehicle:manage`. Never the odometer (POST …/readings) or the owner
     * (POST …/transfer).
     */
    public function update(SaveVehicleRequest $request, Vehicle $vehicle, UpdateVehicle $update, FleetQueries $fleet): VehicleResource
    {
        Gate::authorize('update', $vehicle);

        return new VehicleResource($fleet->view($update->handle($vehicle, $request->vehicleAttributes())));
    }

    /**
     * Archive a vehicle
     *
     * `vehicle:manage`. Vehicles are never deleted: history stays and the
     * plate becomes free for another vehicle.
     */
    public function destroy(Vehicle $vehicle, UpdateVehicle $update, FleetQueries $fleet): VehicleResource
    {
        Gate::authorize('update', $vehicle);

        return new VehicleResource($fleet->view($update->archive($vehicle)));
    }

    /**
     * Transfer ownership
     *
     * Staff with `vehicle:manage`. Service history (readings, maintenance
     * state) stays with the vehicle; documents stay with the account they
     * were filed under, so the new owner never sees the previous owner's.
     * The receiving account must be active.
     */
    public function transfer(TransferVehicleRequest $request, Vehicle $vehicle, FleetQueries $fleet, TransferVehicle $transfer): VehicleResource
    {
        Gate::authorize('transfer', $vehicle);
        $to = $fleet->account($request->string('customer_account_id')->lower()->toString());
        Gate::authorize('createWorkFor', $to);

        return new VehicleResource($fleet->view($transfer->handle($vehicle, $to, $request->effectiveOn())));
    }

    /**
     * Ownership history
     *
     * Staff only (it names other customer accounts).
     */
    public function ownerships(PaginatedRequest $request, Vehicle $vehicle, FleetQueries $fleet): VehicleOwnershipCollection
    {
        Gate::authorize('viewOwnerships', $vehicle);

        return new VehicleOwnershipCollection($fleet->ownerships($vehicle, $request->perPage()));
    }

    /**
     * Vehicle PMS health
     *
     * Every catalogue task evaluated against the vehicle, most urgent first,
     * with the health score (steep: overdue critical −25, overdue −15,
     * due-soon critical −8, due-soon −4). Needs repair_pms.
     */
    public function health(Vehicle $vehicle, FleetQueries $fleet): VehicleHealthResource
    {
        Gate::authorize('viewHealth', $vehicle);

        return new VehicleHealthResource($fleet->health($vehicle), $fleet->tasksById(), $fleet->todayDate());
    }
}
