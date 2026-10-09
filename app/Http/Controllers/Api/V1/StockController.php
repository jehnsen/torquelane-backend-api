<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\InventoryQueries;
use App\Actions\Inventory\RecordOpeningStock;
use App\Http\Requests\ListMovesRequest;
use App\Http\Requests\ListStockRequest;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\RecordOpeningStockRequest;
use App\Http\Requests\ReorderRequest;
use App\Http\Resources\InventoryJson;
use App\Http\Resources\StockBalanceCollection;
use App\Http\Resources\StockLocationCollection;
use App\Http\Resources\StockMoveCollection;
use App\Http\Resources\StockMoveResource;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMove;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Stock itself: where it is, what it is worth, how it moved, what is running
 * low and what to buy. Staff only. Every figure here is derived from the
 * ledger; nothing writes a balance except through a document (receipt, issue
 * to a job, count, transfer) or an opening balance.
 */
final class StockController
{
    /**
     * List stock locations
     *
     * `inventory:view`. The locations of the branches the caller may see
     * (each branch has one store).
     */
    public function locations(PaginatedRequest $request, InventoryQueries $queries): StockLocationCollection
    {
        Gate::authorize('viewAny', StockLocation::class);

        return new StockLocationCollection($queries->locations($request->perPage()));
    }

    /**
     * Stock on hand
     *
     * `inventory:view`. Every item in every location of the selected branch
     * (or `branch_id`), by SKU: quantity, average cost, value (on hand ×
     * average, rounded once), the branch's reorder point and bin.
     * `summary` covers the whole filtered set: its value is summed exactly
     * and rounded once, so it can differ by a centavo from the rows added up.
     */
    public function onHand(ListStockRequest $request, InventoryQueries $queries): StockBalanceCollection
    {
        Gate::authorize('viewAny', StockBalance::class);

        return new StockBalanceCollection($queries->onHand($request->filters(), $request->perPage()), $queries->onHandSummary($request->filters()));
    }

    /**
     * Item movements
     *
     * `inventory:view`. The stock ledger, newest first, cursor-paged. Filter
     * by item, location, type, source and Manila business dates (`from`,
     * `to`). Quantity is signed; `source_reference` is the receipt,
     * transfer, count or work order behind the move.
     */
    public function moves(ListMovesRequest $request, InventoryQueries $queries): StockMoveCollection
    {
        Gate::authorize('viewAny', StockMove::class);

        return new StockMoveCollection($queries->moves($request->filters(), $request->perPage()));
    }

    /**
     * Low-stock alerts
     *
     * `inventory:view`. Derived on read from the balances and each branch's
     * reorder points; never stored. Worst first: out of stock (critical),
     * then at or under the reorder point (warning). An alert's id is
     * `stock:<item>:<location>`.
     */
    public function alerts(ListStockRequest $request, InventoryQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', StockBalance::class);
        $alerts = $queries->alerts(array_intersect_key($request->filters(), ['branch_id' => true]));

        return new JsonResponse([
            'data' => array_map(fn (array $alert): array => [
                'id' => $alert['id'],
                'severity' => $alert['severity'],
                'item' => InventoryJson::item($alert['item']),
                'branch_id' => $alert['location']->branch_id,
                'location_id' => $alert['location']->id,
                'location_name' => $alert['location']->name,
                'on_hand' => InventoryJson::quantity(BigDecimal::of($alert['on_hand'])),
                'reorder_point' => InventoryJson::quantity(BigDecimal::of($alert['reorder_point'])),
                'message' => $alert['message'],
            ], $alerts),
            'meta' => [
                'total' => count($alerts),
                'critical' => count(array_filter($alerts, fn (array $a): bool => $a['severity'] === 'critical')),
            ],
        ]);
    }

    /**
     * Reorder
     *
     * `inventory:view`. For each active stocked item, per branch: on hand, on
     * order (issued purchase orders still to arrive), the reorder point and
     * quantity, and the fleet forecast's shortfall for the same SKU over
     * `horizon_weeks` (default 6; Phase 4's forecast, per active customer
     * account). From those: `reason` (`stockout`, `below_reorder_point`,
     * `forecast_shortfall`) and what to buy, in stock and in purchase units.
     * Only rows that need something, unless `all`. The forecast is the
     * fleet's, so it is counted once, against the first branch in scope.
     */
    public function reorder(ReorderRequest $request, InventoryQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', StockBalance::class);
        $rows = $queries->reorder($request->filters());

        return new JsonResponse([
            'data' => array_map(function (array $row): array {
                $item = $row['item'];
                $advice = $row['advice'];

                return [
                    'item' => InventoryJson::item($item) + [
                        'purchase_uom' => $item->purchase_uom ?? $item->uom,
                        'purchase_uom_factor' => InventoryJson::quantity($item->purchase_uom_factor),
                        'preferred_vendor_id' => $item->preferred_vendor_id,
                        'preferred_vendor_name' => $item->preferredVendor?->name,
                    ],
                    'branch_id' => $row['branch_id'],
                    'on_hand' => InventoryJson::quantity($row['on_hand']),
                    'on_order' => InventoryJson::quantity($row['on_order']),
                    'forecast_shortfall' => InventoryJson::quantity($row['forecast_shortfall']),
                    'reorder_point' => InventoryJson::quantity($row['reorder_point']),
                    'reorder_qty' => InventoryJson::quantity($row['reorder_qty']),
                    'projected' => InventoryJson::quantity($advice->projected),
                    'reason' => $advice->reason,
                    'needs_order' => $advice->needsOrder(),
                    'suggested_stock_quantity' => InventoryJson::quantity($advice->suggestedStockQuantity),
                    'suggested_purchase_quantity' => InventoryJson::quantity($advice->suggestedPurchaseQuantity),
                    'last_unit_cost_cents' => $row['avg_cost_cents'],
                ];
            }, $rows),
            'meta' => ['total' => count($rows), 'needing_order' => count(array_filter($rows, fn (array $r): bool => $r['advice']->needsOrder()))],
        ]);
    }

    /**
     * Record an opening balance
     *
     * `inventory:manage` in the location's branch. The first count of items
     * in a location, each at a stated cost (an `opening` move). Refused for
     * an item that already has stock history there: correct it with a stock
     * count instead.
     */
    public function opening(RecordOpeningStockRequest $request, RecordOpeningStock $record): JsonResponse
    {
        $location = StockLocation::query()->findOrFail($request->locationId());
        Gate::authorize('recordOpening', $location);

        $moves = $record->handle($location, $request->lines(), $request->reason());
        foreach ($moves as $move) {
            $move->load('item');
        }

        return new JsonResponse(['data' => array_map(fn (StockMove $move): array => (new StockMoveResource($move))->resolve(), $moves)], 201);
    }
}
