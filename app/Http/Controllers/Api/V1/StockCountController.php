<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\InventoryQueries;
use App\Actions\Inventory\ManageStockCounts;
use App\Http\Requests\EnterStockCountRequest;
use App\Http\Requests\ListStockDocumentsRequest;
use App\Http\Requests\OpenStockCountRequest;
use App\Http\Resources\StockCountCollection;
use App\Http\Resources\StockCountResource;
use App\Models\StockCount;
use App\Models\StockLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Stock counts: a sheet for one location, counted quantities, then posted as
 * adjustments with a reason. Stock never changes by editing a quantity.
 */
final class StockCountController
{
    /**
     * List stock counts
     *
     * `inventory:view`. Newest first; `status` is `open`, `posted` or `cancelled`.
     */
    public function index(ListStockDocumentsRequest $request, InventoryQueries $queries): StockCountCollection
    {
        Gate::authorize('viewAny', StockCount::class);

        return new StockCountCollection($queries->counts($request->filters(), $request->perPage()));
    }

    /**
     * Draw a count sheet
     *
     * `inventory:manage` in the location's branch. Lists the items the books
     * hold there (and any in `item_ids`), each with what the books say now.
     * Numbered from the `stock_count` series.
     */
    public function store(OpenStockCountRequest $request, ManageStockCounts $counts): JsonResponse
    {
        $location = StockLocation::query()->findOrFail($request->locationId());
        Gate::authorize('create', [StockCount::class, $location->branch_id]);

        return (new StockCountResource($counts->open($location, $request->sheet())))->response()->setStatusCode(201);
    }

    /**
     * Show a count
     */
    public function show(StockCount $stockCount): StockCountResource
    {
        Gate::authorize('view', $stockCount);

        return new StockCountResource($stockCount);
    }

    /**
     * Enter counted quantities
     *
     * `inventory:manage`, while the count is open. A line may carry its own
     * reason; `counted_quantity: null` un-counts it.
     */
    public function enter(EnterStockCountRequest $request, StockCount $stockCount, ManageStockCounts $counts): StockCountResource
    {
        Gate::authorize('update', $stockCount);

        return new StockCountResource($counts->enter($stockCount, $request->lines()));
    }

    /**
     * Post a count
     *
     * `inventory:manage`. Every counted line's variance against what the books
     * hold now becomes an `adjustment` move carrying a reason (the line's, else
     * the count's; a variance with neither is refused). Uncounted lines are
     * left alone.
     */
    public function post(StockCount $stockCount, ManageStockCounts $counts): StockCountResource
    {
        Gate::authorize('update', $stockCount);

        return new StockCountResource($counts->post($stockCount));
    }

    /**
     * Cancel a count
     *
     * `inventory:manage`. Only an open sheet; nothing moves.
     */
    public function cancel(StockCount $stockCount, ManageStockCounts $counts): StockCountResource
    {
        Gate::authorize('update', $stockCount);

        return new StockCountResource($counts->cancel($stockCount));
    }
}
