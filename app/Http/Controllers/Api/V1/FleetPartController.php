<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fleet\FleetQueries;
use App\Actions\Parts\PartsQueries;
use App\Actions\Parts\SaveFleetPart;
use App\Http\Requests\ListFleetPartsRequest;
use App\Http\Requests\SaveFleetPartRequest;
use App\Http\Resources\FleetPartCollection;
use App\Http\Resources\FleetPartResource;
use App\Models\FleetPart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A customer account's OWN spare parts (../web's fleet parts catalogue, seen
 * from the portal): what the account keeps on its shelf for its vehicles,
 * and which service tasks consume each part. Not the shop's inventory.
 */
final class FleetPartController
{
    /**
     * List spare parts
     *
     * Portal users see their account's parts; staff every account's (narrow
     * with `customer_account_id`). Active parts only unless
     * `include_inactive`. Needs repair_pms.
     */
    public function index(ListFleetPartsRequest $request, PartsQueries $parts): FleetPartCollection
    {
        Gate::authorize('viewAny', FleetPart::class);

        return new FleetPartCollection($parts->page($request->filters(), $request->perPage()));
    }

    /**
     * Add a spare part
     *
     * `settings:manage`. Staff name the `customer_account_id`; a portal
     * user's part goes to their own account. `current_stock` is the opening
     * count; afterwards stock changes only by receiving a purchase order.
     */
    public function store(SaveFleetPartRequest $request, FleetQueries $fleet, SaveFleetPart $save): JsonResponse
    {
        $account = $fleet->account($request->accountId());
        Gate::authorize('create', [FleetPart::class, $account]);

        return (new FleetPartResource($save->create($account, $request->partAttributes(), $request->usages())))->response()->setStatusCode(201);
    }

    /**
     * Show a spare part
     */
    public function show(FleetPart $fleetPart): FleetPartResource
    {
        Gate::authorize('view', $fleetPart);

        return new FleetPartResource($fleetPart->load('usages'));
    }

    /**
     * Update a spare part
     *
     * `settings:manage`. Stock and account cannot change here. `usages`, when
     * sent, replaces the part's task links.
     */
    public function update(SaveFleetPartRequest $request, FleetPart $fleetPart, SaveFleetPart $save): FleetPartResource
    {
        Gate::authorize('update', $fleetPart);

        return new FleetPartResource($save->update($fleetPart, $request->partAttributes(), $request->usages()));
    }

    /**
     * Remove a spare part
     *
     * Only a part never ordered; one on a purchase order answers 409 (set
     * `is_active: false` instead).
     */
    public function destroy(FleetPart $fleetPart, SaveFleetPart $save): Response
    {
        Gate::authorize('delete', $fleetPart);
        $save->delete($fleetPart);

        return response()->noContent();
    }
}
