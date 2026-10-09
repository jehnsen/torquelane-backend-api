<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingBranch;
use App\Actions\Billing\BillingQueries;
use App\Actions\Billing\RecordPayment;
use App\Actions\Fleet\FleetQueries;
use App\Documents\BillingPdf;
use App\Http\Requests\AllocatePaymentRequest;
use App\Http\Requests\ListPaymentsRequest;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Requests\VoidDocumentRequest;
use App\Http\Resources\PaymentCollection;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Payments (Phase 7): numbered from the `payment` series (`PAY-…`) when
 * recorded, spread over one account's open invoices, the rest held as
 * credit; voided, never edited.
 */
final class PaymentController
{
    /**
     * List payments
     *
     * `billing:view`. Newest first; `status` `posted` or `void`.
     */
    public function index(ListPaymentsRequest $request, BillingQueries $billing): PaymentCollection
    {
        Gate::authorize('viewAny', Payment::class);

        return new PaymentCollection($billing->paymentPage($request->filters(), $request->perPage()));
    }

    /**
     * Record a payment
     *
     * `billing:manage` (staff); requires an `Idempotency-Key` (a retry
     * replays the first answer, never a second payment). `method` cash,
     * gcash, maya, card, bank_transfer or check (all but cash need
     * `reference_no`); `amount_cents`; `received_on` (default today, never in
     * the future). `allocations` (`invoice_id`, `amount_cents`) spread it over
     * the account's open invoices; without them it goes to the oldest due
     * first. What is not allocated is the customer's credit. A fully paid
     * invoice stamps its jobs collected. Queues `payment.received`.
     */
    public function store(RecordPaymentRequest $request, RecordPayment $record, FleetQueries $fleet, BillingBranch $branches): JsonResponse
    {
        $account = $fleet->account($request->accountId());
        $branchId = $branches->resolve($request->branchId());
        Gate::authorize('create', [Payment::class, $account, $branchId]);

        return (new PaymentResource($record->record($account, ['branch_id' => $branchId] + $request->payment())))->response()->setStatusCode(201);
    }

    /**
     * Show a payment
     */
    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return new PaymentResource($payment);
    }

    /**
     * Apply a payment's credit
     *
     * `billing:manage`. Allocates what is left of the payment to open
     * invoices of its account, as `allocations` says, or oldest due first.
     */
    public function allocate(AllocatePaymentRequest $request, Payment $payment, RecordPayment $record): PaymentResource
    {
        Gate::authorize('allocate', $payment);

        return new PaymentResource($record->allocate($payment, $request->allocations()));
    }

    /**
     * Void a payment
     *
     * `billing:void`, with a reason. It keeps its number; every invoice it
     * paid is owed again (and a job it had settled is no longer collected).
     */
    public function void(VoidDocumentRequest $request, Payment $payment, RecordPayment $record): PaymentResource
    {
        Gate::authorize('void', $payment);

        return new PaymentResource($record->void($payment, $request->reason()));
    }

    /**
     * Acknowledgment receipt PDF
     *
     * The printed acknowledgment of the payment (`application/pdf`): what was
     * received and which invoices it went to. Not an invoice.
     */
    public function pdf(Payment $payment, BillingPdf $pdf): Response
    {
        Gate::authorize('view', $payment);

        return $pdf->payment($payment);
    }
}
