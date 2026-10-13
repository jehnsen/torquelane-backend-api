<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingQueries;
use App\Actions\Billing\ManageCreditNotes;
use App\Http\Requests\IssueCreditNoteRequest;
use App\Http\Requests\ListSettlementsRequest;
use App\Http\Requests\VoidDocumentRequest;
use App\Http\Resources\CreditNoteCollection;
use App\Http\Resources\CreditNoteResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Credit notes (Phase 12): numbered from the `credit_note` series (`CN-…`)
 * when issued against an invoice; voided, never edited.
 */
final class CreditNoteController
{
    /**
     * List credit notes
     *
     * `billing:view`. Newest first; `status` `issued` or `void`,
     * `customer_account_id`, `branch_id`, `invoice_id`.
     */
    public function index(ListSettlementsRequest $request, BillingQueries $billing): CreditNoteCollection
    {
        Gate::authorize('viewAny', CreditNote::class);

        return new CreditNoteCollection($billing->settlementPage($billing->creditNotes()->with(BillingQueries::CREDIT_NOTE_RELATIONS), $request->filters(), 'issued_on', $request->perPage()));
    }

    /**
     * Credit an invoice
     *
     * `billing:void` (staff); requires an `Idempotency-Key`. `reason`,
     * `issued_on` (default today), and `lines`: an `invoice_line_id` alone
     * credits what is left of that line; with `quantity`, that many at the
     * line's price; with `unit_price_cents`, that amount. A line with no
     * `invoice_line_id` is an allowance (`description`, `unit_price_cents`).
     * The notes on an invoice never exceed what it billed.
     *
     * The note comes off the invoice's balance as far as the invoice still
     * owed (`applied_cents`); the rest is the customer's credit
     * (`credit_cents`), paid out by a refund. Books Dr Sales Returns &
     * Allowances, Dr Output VAT / Cr Accounts Receivable, Cr Customer
     * Deposits.
     */
    public function store(IssueCreditNoteRequest $request, Invoice $invoice, ManageCreditNotes $notes): JsonResponse
    {
        Gate::authorize('create', [CreditNote::class, $invoice]);

        return (new CreditNoteResource($notes->issue($invoice, $request->creditNote())))->response()->setStatusCode(201);
    }

    /**
     * Show a credit note
     */
    public function show(CreditNote $credit_note): CreditNoteResource
    {
        Gate::authorize('view', $credit_note);

        return new CreditNoteResource($credit_note);
    }

    /**
     * Void a credit note
     *
     * `billing:void`, with a reason. Only while none of its credit has been
     * refunded. The invoice owes again what the note had taken off it.
     */
    public function void(VoidDocumentRequest $request, CreditNote $credit_note, ManageCreditNotes $notes): CreditNoteResource
    {
        Gate::authorize('void', $credit_note);

        return new CreditNoteResource($notes->void($credit_note, $request->reason()));
    }
}
