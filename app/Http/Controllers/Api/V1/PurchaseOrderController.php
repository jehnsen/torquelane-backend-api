<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fleet\FleetQueries;
use App\Actions\PurchaseOrders\ProgressPurchaseOrder;
use App\Actions\PurchaseOrders\PurchaseOrderQueries;
use App\Actions\PurchaseOrders\RaisePurchaseOrders;
use App\Domain\PurchaseOrders\ExportTable;
use App\Domain\PurchaseOrders\PurchaseOrderExport;
use App\Exports\SpreadsheetWriter;
use App\Http\Requests\CancelPurchaseOrderRequest;
use App\Http\Requests\ExportPurchaseOrderRequest;
use App\Http\Requests\ListPurchaseOrdersRequest;
use App\Http\Requests\RaisePurchaseOrdersRequest;
use App\Http\Resources\PurchaseOrderCollection;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A customer account's purchase orders for its own spare parts: raised from
 * the demand forecast (one draft per preferred vendor, numbered
 * PO-YYYY-NNNN), then sent (issuing is approving the spend, within the
 * issuer's band), received (restocks the account's parts) or cancelled.
 * An issued order never changes but for its status (R7).
 */
final class PurchaseOrderController
{
    /**
     * List purchase orders
     *
     * Newest first. Portal users see their account's; staff every account's
     * (narrow with `customer_account_id`); filter by `status`. Needs
     * repair_pms.
     */
    public function index(ListPurchaseOrdersRequest $request, PurchaseOrderQueries $orders): PurchaseOrderCollection
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        return new PurchaseOrderCollection($orders->page($request->filters(), $request->perPage()));
    }

    /**
     * Raise purchase requests from the forecast
     *
     * `po:issue`. Name the parts to order (`part_ids`) over the horizon the
     * forecast was read at; the server recomputes the forecast and orders
     * each part's shortfall at its unit cost, one draft per preferred
     * vendor. Quantities and prices are never read from the request. A
     * suspended account takes no new orders (403 account_suspended).
     */
    public function store(RaisePurchaseOrdersRequest $request, FleetQueries $fleet, RaisePurchaseOrders $raise, PurchaseOrderQueries $orders): JsonResponse
    {
        $account = $fleet->account($request->accountId());
        Gate::authorize('create', [PurchaseOrder::class, $account]);
        Gate::authorize('createWorkFor', $account);

        $created = $raise->handle($account, $request->horizonWeeks(), $request->partIds(), $request->notes());

        return new JsonResponse([
            'data' => array_map(fn (PurchaseOrder $order): array => (new PurchaseOrderResource($orders->view($order)))->resolve($request), $created),
        ], 201);
    }

    /**
     * Show a purchase order
     */
    public function show(PurchaseOrder $purchaseOrder, PurchaseOrderQueries $orders): PurchaseOrderResource
    {
        Gate::authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($orders->view($purchaseOrder));
    }

    /**
     * Send (issue) a purchase order
     *
     * draft → sent. `po:issue`, and the order's total must sit within the
     * caller's approval band under the account's settings (403 otherwise,
     * naming the limit).
     */
    public function send(PurchaseOrder $purchaseOrder, ProgressPurchaseOrder $progress, PurchaseOrderQueries $orders): PurchaseOrderResource
    {
        Gate::authorize('progress', $purchaseOrder);

        return new PurchaseOrderResource($orders->view($progress->send($purchaseOrder)));
    }

    /**
     * Receive a purchase order
     *
     * sent → received. Each line's quantity goes back into the account's own
     * stock of that part.
     */
    public function receive(PurchaseOrder $purchaseOrder, ProgressPurchaseOrder $progress, PurchaseOrderQueries $orders): PurchaseOrderResource
    {
        Gate::authorize('progress', $purchaseOrder);

        return new PurchaseOrderResource($orders->view($progress->receive($purchaseOrder)));
    }

    /**
     * Cancel a purchase order
     *
     * draft or sent → cancelled, with a `reason`. Its number is not reused.
     */
    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, ProgressPurchaseOrder $progress, PurchaseOrderQueries $orders): PurchaseOrderResource
    {
        Gate::authorize('progress', $purchaseOrder);

        return new PurchaseOrderResource($orders->view($progress->cancel($purchaseOrder, $request->reason())));
    }

    /**
     * Export purchase orders
     *
     * The list's filters as a spreadsheet (`format` xlsx, the default, or
     * csv): one row per line, ../web `exportPurchaseOrdersToExcel`. Money as
     * pesos with two decimals. At most 5,000 orders; filter to narrow.
     */
    public function export(ListPurchaseOrdersRequest $request, PurchaseOrderQueries $orders): Response
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        return self::download(PurchaseOrderExport::orders($orders->export($request->filters())), 'purchase-orders', $request->exportFormat());
    }

    /**
     * Export a purchase order
     *
     * One order, one row per line (../web `exportPurchaseOrderToExcel`),
     * named after its reference.
     */
    public function exportOne(ExportPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('view', $purchaseOrder);

        return self::download(PurchaseOrderExport::order(PurchaseOrderQueries::exportOrder($purchaseOrder->load('lines'))), $purchaseOrder->reference, $request->exportFormat());
    }

    private static function download(ExportTable $table, string $name, string $format): Response
    {
        $csv = $format === 'csv';
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $name).($csv ? '.csv' : '.xlsx');

        return new Response($csv ? SpreadsheetWriter::csv($table) : SpreadsheetWriter::xlsx($table), 200, [
            'Content-Type' => $csv ? SpreadsheetWriter::CSV_TYPE : SpreadsheetWriter::XLSX_TYPE,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
