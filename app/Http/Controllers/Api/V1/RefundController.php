<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingQueries;
use App\Actions\Billing\ManageRefunds;
use App\Http\Requests\ListSettlementsRequest;
use App\Http\Requests\PayRefundRequest;
use App\Http\Requests\VoidDocumentRequest;
use App\Http\Resources\RefundCollection;
use App\Http\Resources\RefundResource;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Refunds (Phase 12): money back to a customer out of credit they hold,
 * numbered from the `refund` series (`RF-…`); voided, never edited.
 */
final class RefundController
{
    /**
     * List refunds
     *
     * `billing:view`. Newest first; `status` `posted` or `void`,
     * `customer_account_id`, `branch_id`.
     */
    public function index(ListSettlementsRequest $request, BillingQueries $billing): RefundCollection
    {
        Gate::authorize('viewAny', Refund::class);

        return new RefundCollection($billing->settlementPage($billing->refunds()->with(['payment', 'creditNote', 'customerAccount']), $request->filters(['status', 'customer_account_id', 'branch_id']), 'refunded_on', $request->perPage()));
    }

    /**
     * Pay a refund
     *
     * `billing:void` (staff); requires an `Idempotency-Key`. Exactly one
     * source: `payment_id` (what that payment has neither allocated nor
     * already refunded) or `credit_note_id` (the credit that note left over).
     * `amount_cents` (default: everything the source still holds), `method`
     * (all but cash need `reference_no`), `refunded_on` (default today),
     * `reason`. To refund money already applied to an invoice, credit the
     * invoice first. Books Dr Customer Deposits / Cr the cash-side account of
     * the method.
     */
    public function store(PayRefundRequest $request, ManageRefunds $refunds, BillingQueries $billing): JsonResponse
    {
        $source = $request->paymentId() !== null
            ? $billing->payments()->findOrFail($request->paymentId())
            : $billing->creditNotes()->findOrFail((string) $request->creditNoteId());
        Gate::authorize('create', [Refund::class, $source]);

        return (new RefundResource($refunds->pay($source, $request->refund())))->response()->setStatusCode(201);
    }

    /**
     * Show a refund
     */
    public function show(Refund $refund): RefundResource
    {
        Gate::authorize('view', $refund);

        return new RefundResource($refund);
    }

    /**
     * Void a refund
     *
     * `billing:void`, with a reason. It keeps its number, and the credit it
     * paid out is the customer's again.
     */
    public function void(VoidDocumentRequest $request, Refund $refund, ManageRefunds $refunds): RefundResource
    {
        Gate::authorize('void', $refund);

        return new RefundResource($refunds->void($refund, $request->reason()));
    }
}
