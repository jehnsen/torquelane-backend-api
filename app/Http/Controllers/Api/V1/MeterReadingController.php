<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fleet\FleetQueries;
use App\Actions\Fleet\RecordReading;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\RecordReadingRequest;
use App\Http\Requests\VoidReadingRequest;
use App\Http\Resources\MeterReadingCollection;
use App\Http\Resources\MeterReadingResource;
use App\Models\MeterReading;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Odometer readings (append-only). The vehicle's current reading and daily
 * rate are derived from them.
 */
final class MeterReadingController
{
    /**
     * Reading history
     *
     * Every row, newest first, void rows included.
     */
    public function index(PaginatedRequest $request, Vehicle $vehicle, FleetQueries $fleet): MeterReadingCollection
    {
        Gate::authorize('view', $vehicle);

        return new MeterReadingCollection($fleet->readings($vehicle, $request->perPage()));
    }

    /**
     * Record a reading
     *
     * `vehicle:update`. Refused below the current reading (422, the
     * frontend's message). A reading implying over 3× or under 0.1× the
     * vehicle's average daily distance is a 422 warning on `value` until
     * re-sent with `confirm_warning: true`. Not in the future, not dated
     * before the current reading.
     */
    public function store(RecordReadingRequest $request, Vehicle $vehicle, RecordReading $record): JsonResponse
    {
        Gate::authorize('recordReading', $vehicle);

        $reading = $record->handle($vehicle, $request->value(), $request->readOn(), $request->boolean('confirm_warning'), $request->source());

        return (new MeterReadingResource($reading))->response()->setStatusCode(201);
    }

    /**
     * Void a reading
     *
     * `vehicle:manage`. Appends a correction row; nothing is edited or
     * deleted. A vehicle keeps at least one effective reading.
     */
    public function void(VoidReadingRequest $request, Vehicle $vehicle, MeterReading $reading, RecordReading $record): JsonResponse
    {
        Gate::authorize('voidReading', $vehicle);

        $void = $record->void($vehicle, $reading, $request->string('reason')->toString());

        return (new MeterReadingResource($void))->response()->setStatusCode(201);
    }
}
