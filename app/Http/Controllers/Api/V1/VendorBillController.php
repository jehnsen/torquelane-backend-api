<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingBranch;
use App\Actions\Payables\ManageVendorBills;
use App\Actions\Payables\PayablesQueries;
use App\Domain\Inventory\StockLedger;
use App\Domain\Invoicing\Invoicing;
use App\Http\Requests\ListPayablesRequest;
use App\Http\Requests\RecordVendorBillRequest;
use App\Http\Requests\VoidDocumentRequest;
use App\Http\Resources\VendorBillCollection;
use App\Http\Resources\VendorBillResource;
use App\Models\GoodsReceiptLine;
use App\Models\Vendor;
use App\Models\VendorBill;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Vendor bills (Phase 12, accounts payable): numbered from the `vendor_bill`
 * series (`VB-…`) and posted to the books when recorded; voided, never
 * edited. Staff only.
 */
final class VendorBillController
{
    /**
     * List vendor bills
     *
     * `payables:view`. Newest first, in the caller's branches. `status`: a
     * status, or `open` (unpaid or partially paid) or `overdue` (open and past
     * due); `vendor_id`, `branch_id`, `q` (our number, the vendor's invoice
     * number, the vendor's name).
     */
    public function index(ListPayablesRequest $request, PayablesQueries $payables): VendorBillCollection
    {
        Gate::authorize('viewAny', VendorBill::class);

        return new VendorBillCollection($payables->billPage($request->filters(), $request->perPage()));
    }

    /**
     * Goods received, not yet billed
     *
     * `payables:view`. Receipt lines no standing bill has claimed, in the
     * caller's branches (`vendor_id` narrows it): what a new bill's `goods`
     * lines can name. `received_value_cents` is what the receipt left in
     * GR/IR for a line that went on the shelf (null for goods bought for one
     * job).
     */
    public function unbilled(ListPayablesRequest $request, PayablesQueries $payables): JsonResponse
    {
        Gate::authorize('viewAny', VendorBill::class);

        return new JsonResponse(['data' => array_values($payables->unbilledReceiptLines($request->vendorId())->map(fn (GoodsReceiptLine $line): array => [
            'goods_receipt_line_id' => $line->id,
            'goods_receipt_id' => $line->goods_receipt_id,
            'receipt_reference' => $line->receipt->reference,
            'received_on' => $line->receipt->received_on->toDateString(),
            'branch_id' => $line->receipt->branch_id,
            'vendor_id' => $line->receipt->order->vendor_id,
            'vendor_name' => $line->receipt->order->vendor_name,
            'purchase_order_reference' => $line->receipt->order->reference,
            'item_id' => $line->item_id,
            'description' => $line->orderLine->description,
            'quantity' => Invoicing::quantity((string) $line->quantity),
            'unit_cost_cents' => $line->unit_cost_cents,
            'line_total_cents' => $line->line_total_cents,
            'received_value_cents' => $line->item_id === null ? null : StockLedger::moveValue($line->stock_quantity, $line->stock_unit_cost_cents),
        ])->all())]);
    }

    /**
     * Record a vendor bill
     *
     * `payables:manage`; requires an `Idempotency-Key`. `vendor_id`,
     * `vendor_invoice_no` (once per vendor), `bill_date` (default today),
     * `due_date` (default: bill date + the vendor's terms),
     * `prices_include_vat` (default false), `vat_rate_pct` (default 12), and
     * `lines`: `kind: goods` names a `goods_receipt_line_id` (billed whole,
     * once; `unit_cost_cents` if the vendor charged other than the receipt's
     * cost) and `kind: expense` an `account_id`, `description` and
     * `unit_cost_cents`. `withholding` (`atc_code`, `rate_pct`) withholds
     * expanded tax on the purchases before VAT; left out, the vendor's usual
     * code and rate apply; `false` withholds nothing. `payment` (`method`,
     * `reference_no`) pays the bill in full on the spot.
     *
     * Books Dr GR/IR (goods on the shelf, at the receipt's value) or the
     * line's account, Dr Purchase Price Variance, Dr Input VAT / Cr Accounts
     * Payable, Cr Withholding Tax Payable.
     */
    public function store(RecordVendorBillRequest $request, ManageVendorBills $bills, BillingBranch $branches): JsonResponse
    {
        $branchId = $branches->resolve($request->branchId());
        Gate::authorize('create', [VendorBill::class, $branchId]);
        $vendor = Vendor::query()->findOrFail($request->vendorId());

        return (new VendorBillResource($bills->record($vendor, ['branch_id' => $branchId] + $request->bill())))->response()->setStatusCode(201);
    }

    /**
     * Show a vendor bill
     */
    public function show(VendorBill $vendor_bill): VendorBillResource
    {
        Gate::authorize('view', $vendor_bill);

        return new VendorBillResource($vendor_bill);
    }

    /**
     * Void a vendor bill
     *
     * `payables:manage`, with a reason. Only a bill with nothing paid (void
     * its payments first). It keeps its number; the books take the mirror
     * entry, dated today; its receipt lines may be billed again.
     */
    public function void(VoidDocumentRequest $request, VendorBill $vendor_bill, ManageVendorBills $bills): VendorBillResource
    {
        Gate::authorize('void', $vendor_bill);

        return new VendorBillResource($bills->void($vendor_bill, $request->reason()));
    }
}
