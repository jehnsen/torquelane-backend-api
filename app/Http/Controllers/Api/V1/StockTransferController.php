<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\InventoryQueries;
use App\Actions\Inventory\TransferStock;
use App\Http\Requests\ListStockDocumentsRequest;
use App\Http\Requests\TransferStockRequest;
use App\Http\Resources\StockTransferCollection;
use App\Http\Resources\StockTransferResource;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Inter-branch transfers: one document, both moves, one transaction.
 */
final class StockTransferController
{
    /**
     * List transfers
     *
     * `inventory:view`. Transfers into or out of the branches the caller may
     * see, newest first.
     */
    public function index(ListStockDocumentsRequest $request, InventoryQueries $queries): StockTransferCollection
    {
        Gate::authorize('viewAny', StockTransfer::class);

        return new StockTransferCollection($queries->transfers($request->filters(), $request->perPage()));
    }

    /**
     * Transfer stock
     *
     * `inventory:manage` in the branch the goods leave. One numbered document:
     * a `transfer_out` at the source's average cost and a `transfer_in` of the
     * same quantity and cost at the destination, in one transaction. A source
     * that would go negative is refused if its branch blocks negative stock.
     */
    public function store(TransferStockRequest $request, TransferStock $transfer): JsonResponse
    {
        $from = StockLocation::query()->findOrFail($request->fromLocationId());
        $to = StockLocation::query()->findOrFail($request->toLocationId());
        Gate::authorize('create', [StockTransfer::class, $from->branch_id]);

        return (new StockTransferResource($transfer->handle($from, $to, $request->lines(), $request->notes())))->response()->setStatusCode(201);
    }

    /**
     * Show a transfer
     */
    public function show(StockTransfer $stockTransfer): StockTransferResource
    {
        Gate::authorize('view', $stockTransfer);

        return new StockTransferResource($stockTransfer);
    }

    /**
     * Reverse a transfer
     *
     * `inventory:manage` in the branch the goods are now in. A new transfer,
     * the other way, naming this one. A transfer is reversed at most once, and
     * a reversal is not itself reversed (make a new transfer).
     */
    public function reverse(StockTransfer $stockTransfer, TransferStock $transfer): JsonResponse
    {
        Gate::authorize('reverse', $stockTransfer);

        return (new StockTransferResource($transfer->reverse($stockTransfer)))->response()->setStatusCode(201);
    }
}
