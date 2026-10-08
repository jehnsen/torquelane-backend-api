<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fleet\FleetQueries;
use App\Actions\WorkOrders\CheckInLookup;
use App\Actions\WorkOrders\CounterCheckIn;
use App\Domain\Fleet\PmsItem;
use App\Http\Requests\CheckInRequest;
use App\Http\Resources\CustomerAccountResource;
use App\Http\Resources\PmsItemResource;
use App\Http\Resources\VehicleResource;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The counter: look a vehicle up by plate or VIN, or register a new
 * customer and vehicle in one go.
 */
final class CheckInController
{
    /**
     * Look up a vehicle by plate or VIN
     *
     * Exact match on the normalised plate (case, spaces, dashes ignored), then
     * the VIN, over the vehicles the caller may see. Under 3 characters the
     * outcome is `idle`; no match is `new` (a 17-character entry is taken as
     * a VIN). A stale odometer is never pre-filled: `form.odometer` is null
     * and `form.odometer_needs_confirmation` true, with the last reading in
     * `last_odometer` for reference.
     */
    public function lookup(CheckInRequest $request, CheckInLookup $lookup, FleetQueries $fleet): JsonResponse
    {
        Gate::authorize('lookup', WorkOrder::class);

        $found = $lookup->handle($request->lookupText());
        $result = $found['result'];
        $form = $found['form'];

        return new JsonResponse(['data' => [
            'outcome' => $result->outcome,
            'matched_on' => $result->matchedOn,
            'vehicle' => $found['vehicle'] === null ? null : (new VehicleResource($fleet->view($found['vehicle'])))->resolve($request),
            'customer' => $found['account'] === null ? null : (new CustomerAccountResource($found['account']))->resolve($request),
            'last_odometer' => $result->lastOdometer,
            'last_odometer_read_on' => $result->lastOdometerReadAt,
            'odometer_age_days' => $result->odometerAgeDays,
            'odometer_stale' => $result->odometerStale,
            'form' => [
                'vehicle_id' => $form->vehicleId,
                'plate_number' => $form->plateNumber,
                'vin' => $form->vin,
                'make' => $form->make,
                'model' => $form->model,
                'year' => $form->year,
                'vehicle_class' => $form->vehicleClass,
                'fuel_type' => $form->fuelType,
                'customer_name' => $form->customerName,
                'customer_contact' => $form->customerContact,
                'customer_email' => $form->customerEmail,
                'assigned_to' => $form->assignedTo,
                'odometer' => $form->odometer,
                'odometer_needs_confirmation' => $form->odometerNeedsConfirmation,
                'is_existing_vehicle' => $form->isExistingVehicle,
            ],
            'suggested_work' => array_map(fn (PmsItem $item): array => (new PmsItemResource($item, $fleet->tasksById()))->resolve($request), $found['suggested']),
        ]]);
    }

    /**
     * Register a new customer and vehicle at the counter
     *
     * Staff with `customer:manage` and `vehicle:manage`. One transaction:
     * the account with its opening consents (service records must be
     * granted), and the vehicle with the reading taken at the counter.
     */
    public function store(CheckInRequest $request, CounterCheckIn $checkIn, FleetQueries $fleet): JsonResponse
    {
        Gate::authorize('checkIn', WorkOrder::class);

        $created = $checkIn->handle($request->customer(), $request->consents(), $request->vehicle(), $request->odometer());

        return new JsonResponse(['data' => [
            'customer' => (new CustomerAccountResource($created['account']))->resolve($request),
            'vehicle' => (new VehicleResource($fleet->view($created['vehicle'])))->resolve($request),
        ]], 201);
    }
}
