<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\InventoryQueries;
use App\Actions\Inventory\ProgressShopPurchaseOrder;
use App\Actions\Inventory\ReceiveGoods;
use App\Actions\Inventory\SaveShopPurchaseOrder;
use App\Http\Requests\ListStockDocumentsRequest;
use App\Http\Requests\ReceiveGoodsRequest;
use App\Http\Requests\SaveShopOrderRequest;
use App\Http\Requests\StockReasonRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Http\Resources\ShopPurchaseOrderCollection;
use App\Http\Resources\ShopPurchaseOrderResource;
use App\Models\ShopPurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The shop's own purchase orders: raised to a vendor for a branch's stock (or
 * one job), numbered at creation from the `shop_purchase_order` series (`SPO-…`), issued,
 * and received against in whole or in part. Not Phase 4's `/purchase-orders`,
 * which are a customer account's own spare parts.
 */
final class ShopPurchaseOrderController
{
    /**
     * List shop purchase orders
     *
     * `inventory:view`. Newest first. `status` is `draft`, `cancelled`, or
     * one of the derived ones: `issued` (nothing in yet), `partially_received`,
     * `received`, or `open` (issued or partially received).
     */
    public function index(ListStockDocumentsRequest $request, InventoryQueries $queries): ShopPurchaseOrderCollection
    {
        Gate::authorize('viewAny', ShopPurchaseOrder::class);

        return new ShopPurchaseOrderCollection($queries->shopOrders($request->filters(), $request->perPage(), $request->page()));
    }

    /**
     * Raise a purchase order
     *
     * `inventory:manage` in `branch_id`. A draft, numbered from the
     * organization's `shop_purchase_order` series (`SPO-…`) in this transaction. Lines are in
     * the purchase unit (quantity × unit cost, rounded once to a centavo by the
     * server): a stocked `item_id`, or a line bought for a job
     * (`work_order_line_id` of a line marked "purchased for job").
     */
    public function store(SaveShopOrderRequest $request, SaveShopPurchaseOrder $save, InventoryQueries $queries): JsonResponse
    {
        Gate::authorize('create', [ShopPurchaseOrder::class, $request->branchId()]);
        /** @var array{branch_id: string, vendor_id: string, notes?: string, expected_on?: string|null, lines: list<array<string, mixed>>} $data */
        $data = $request->order();

        return (new ShopPurchaseOrderResource($queries->view($save->create($data))))->response()->setStatusCode(201);
    }

    /**
     * Show a purchase order
     */
    public function show(ShopPurchaseOrder $shopPurchaseOrder, InventoryQueries $queries): ShopPurchaseOrderResource
    {
        Gate::authorize('view', $shopPurchaseOrder);

        return new ShopPurchaseOrderResource($queries->view($shopPurchaseOrder));
    }

    /**
     * Edit a draft
     *
     * `inventory:manage`. Only a draft; an issued order's lines never change.
     */
    public function update(SaveShopOrderRequest $request, ShopPurchaseOrder $shopPurchaseOrder, SaveShopPurchaseOrder $save, InventoryQueries $queries): ShopPurchaseOrderResource
    {
        Gate::authorize('progress', $shopPurchaseOrder);

        return new ShopPurchaseOrderResource($queries->view($save->update($shopPurchaseOrder, $request->order())));
    }

    /**
     * Issue a purchase order
     *
     * `inventory:manage`. Sends the draft to the vendor; its lines freeze.
     */
    public function issue(ShopPurchaseOrder $shopPurchaseOrder, ProgressShopPurchaseOrder $progress, InventoryQueries $queries): ShopPurchaseOrderResource
    {
        Gate::authorize('progress', $shopPurchaseOrder);

        return new ShopPurchaseOrderResource($queries->view($progress->issue($shopPurchaseOrder)));
    }

    /**
     * Cancel a purchase order
     *
     * `inventory:manage`, with a reason, while nothing has been received
     * against it.
     */
    public function cancel(StockReasonRequest $request, ShopPurchaseOrder $shopPurchaseOrder, ProgressShopPurchaseOrder $progress, InventoryQueries $queries): ShopPurchaseOrderResource
    {
        Gate::authorize('progress', $shopPurchaseOrder);

        return new ShopPurchaseOrderResource($queries->view($progress->cancel($shopPurchaseOrder, $request->reason())));
    }

    /**
     * Receive goods
     *
     * `inventory:manage`. Takes in some or all of the outstanding quantity of
     * an issued order: one numbered goods receipt, and for each stocked line a
     * `receipt` move into the branch's store (the purchase unit converted to
     * the stock unit; the average cost moves with it). More than is
     * outstanding is refused. Partial receipts leave the order
     * `partially_received`.
     */
    public function receive(ReceiveGoodsRequest $request, ShopPurchaseOrder $shopPurchaseOrder, ReceiveGoods $receive): JsonResponse
    {
        Gate::authorize('progress', $shopPurchaseOrder);

        return (new GoodsReceiptResource($receive->receive($shopPurchaseOrder, $request->receipt())))->response()->setStatusCode(201);
    }
}
