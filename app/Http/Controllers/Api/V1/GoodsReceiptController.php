<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\InventoryQueries;
use App\Actions\Inventory\ReceiveGoods;
use App\Http\Requests\ListStockDocumentsRequest;
use App\Http\Requests\StockReasonRequest;
use App\Http\Resources\GoodsReceiptCollection;
use App\Http\Resources\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use Illuminate\Support\Facades\Gate;

/**
 * Goods receipts: the issued stock documents behind every `receipt` move.
 * Immutable; a mistake is voided, never edited.
 */
final class GoodsReceiptController
{
    /**
     * List goods receipts
     *
     * `inventory:view`. Newest first; `status` is `posted` or `voided`.
     */
    public function index(ListStockDocumentsRequest $request, InventoryQueries $queries): GoodsReceiptCollection
    {
        Gate::authorize('viewAny', GoodsReceipt::class);

        return new GoodsReceiptCollection($queries->goodsReceipts($request->filters(), $request->perPage()));
    }

    /**
     * Show a goods receipt
     */
    public function show(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        Gate::authorize('view', $goodsReceipt);

        return new GoodsReceiptResource($goodsReceipt);
    }

    /**
     * Void a goods receipt
     *
     * `inventory:manage`, with a reason. The receipt stays on record, marked
     * void; every move it made is undone by a compensating `return` move, and
     * the purchase order's status follows. Under a branch that blocks
     * negative stock this is refused if the goods have already been used.
     */
    public function void(StockReasonRequest $request, GoodsReceipt $goodsReceipt, ReceiveGoods $receive): GoodsReceiptResource
    {
        Gate::authorize('void', $goodsReceipt);

        return new GoodsReceiptResource($receive->void($goodsReceipt, $request->reason()));
    }
}
