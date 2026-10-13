<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingBranch;
use App\Actions\Payables\PayablesQueries;
use App\Actions\Payables\PayVendors;
use App\Http\Requests\ListPayablesRequest;
use App\Http\Requests\RecordVendorPaymentRequest;
use App\Http\Requests\VoidDocumentRequest;
use App\Http\Resources\VendorPaymentCollection;
use App\Http\Resources\VendorPaymentResource;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Payments to vendors (Phase 12): the cash-out voucher, numbered from the
 * `vendor_payment` series (`PV-…`), allocated in full to that vendor's bills
 * as it is recorded; voided, never edited. Staff only.
 */
final class VendorPaymentController
{
    /**
     * List payments to vendors
     *
     * `payables:view`. Newest first; `status` `posted` or `void`, `method`,
     * `vendor_id`, `branch_id`, `q` (number or reference).
     */
    public function index(ListPayablesRequest $request, PayablesQueries $payables): VendorPaymentCollection
    {
        Gate::authorize('viewAny', VendorPayment::class);

        return new VendorPaymentCollection($payables->paymentPage($request->filters(), $request->perPage()));
    }

    /**
     * Pay a vendor
     *
     * `payables:manage`; requires an `Idempotency-Key`. `method` cash, gcash,
     * maya, card, bank_transfer or check (all but cash need `reference_no`);
     * `paid_on` (default today). Either `allocations` (`vendor_bill_id`,
     * `amount_cents`: the bills it settles) or `amount_cents` alone, spread
     * over the vendor's open bills at the branch, oldest due first. A payment
     * settles bills and nothing more: money that pays no bill is refused.
     * Books Dr Accounts Payable / Cr the cash-side account of the method.
     */
    public function store(RecordVendorPaymentRequest $request, PayVendors $pay, BillingBranch $branches): JsonResponse
    {
        $branchId = $branches->resolve($request->branchId());
        Gate::authorize('create', [VendorPayment::class, $branchId]);
        $vendor = Vendor::query()->findOrFail($request->vendorId());

        return (new VendorPaymentResource($pay->record($vendor, ['branch_id' => $branchId] + $request->payment())))->response()->setStatusCode(201);
    }

    /**
     * Show a payment to a vendor
     */
    public function show(VendorPayment $vendor_payment): VendorPaymentResource
    {
        Gate::authorize('view', $vendor_payment);

        return new VendorPaymentResource($vendor_payment);
    }

    /**
     * Void a payment to a vendor
     *
     * `payables:manage`, with a reason. It keeps its number; every bill it
     * paid is owed again.
     */
    public function void(VoidDocumentRequest $request, VendorPayment $vendor_payment, PayVendors $pay): VendorPaymentResource
    {
        Gate::authorize('void', $vendor_payment);

        return new VendorPaymentResource($pay->void($vendor_payment, $request->reason()));
    }

    /**
     * Payables aging
     *
     * `payables:view`. What each vendor is owed as of `as_of` (default
     * today), by days past due: current, 1–30, 31–60, 61–90, over 90. A
     * balance as of a past date counts only what was billed, paid and voided
     * by then.
     */
    public function aging(ListPayablesRequest $request, PayablesQueries $payables): JsonResponse
    {
        Gate::authorize('viewAny', VendorBill::class);

        return new JsonResponse(['data' => $payables->aging($request->asOf(), $request->vendorId())]);
    }
}
