<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingQueries;
use App\Documents\BillingPdf;
use App\Http\Requests\BillingQueueRequest;
use App\Http\Requests\ReceivablesRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\WorkOrderCollection;
use App\Models\CustomerAccount;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Receivables (Phase 7): the billing queue, aging, revenue (staff), and a
 * customer account's balance and statement (staff, or the account's own
 * portal users).
 */
final class ReceivablesController
{
    /**
     * Billing queue
     *
     * `billing:view` (staff). Closed jobs not yet invoiced, oldest finished
     * first, in the caller's branches; `customer_account_id` narrows it.
     */
    public function queue(BillingQueueRequest $request, BillingQueries $billing): WorkOrderCollection
    {
        Gate::authorize('viewReceivables', Invoice::class);

        return new WorkOrderCollection($billing->queue($request->accountId(), $request->perPage()));
    }

    /**
     * Receivables aging
     *
     * `billing:view` (staff). Each account's open balances as of `as_of`
     * (default today), by days past due: current, 1–30, 31–60, 61–90, over
     * 90; with the account's unallocated credit. A balance as of a past date
     * counts only what was issued, paid and voided by then.
     */
    public function aging(ReceivablesRequest $request, BillingQueries $billing): JsonResponse
    {
        Gate::authorize('viewReceivables', Invoice::class);

        return new JsonResponse(['data' => $billing->aging($request->asOf(), $request->accountId())]);
    }

    /**
     * Revenue: accrual and cash
     *
     * `billing:view` (staff). Over `from`..`to` (business dates): invoices
     * issued and still standing (net sales, VAT, total), and payments received
     * and still standing (by method).
     */
    public function revenue(ReceivablesRequest $request, BillingQueries $billing): JsonResponse
    {
        Gate::authorize('viewReceivables', Invoice::class);

        return new JsonResponse(['data' => $billing->revenue($request->from(), $request->to())]);
    }

    /**
     * Account balance
     *
     * `billing:view`. What the account owes on open invoices, what is
     * overdue, its unallocated credit, and where it stands against its credit
     * limit (`over_limit` warns; new work is never blocked).
     */
    public function balance(Request $request, CustomerAccount $customerAccount, BillingQueries $billing): JsonResponse
    {
        Gate::authorize('viewBilling', $customerAccount);
        $balance = $billing->balance($customerAccount);

        return new JsonResponse(['data' => array_merge($balance, [
            'open_invoices' => array_map(fn (Invoice $invoice): array => (new InvoiceResource($invoice))->toArray($request), $balance['open_invoices']),
        ])]);
    }

    /**
     * Statement of account
     *
     * `billing:view`. Over `from`..`to` (default the last 30 days): the
     * balance brought forward, each invoice, payment and void with the running
     * balance, and the closing balance.
     */
    public function statement(ReceivablesRequest $request, CustomerAccount $customerAccount, BillingQueries $billing): JsonResponse
    {
        Gate::authorize('viewBilling', $customerAccount);

        return new JsonResponse(['data' => $billing->statement($customerAccount, $request->from(), $request->to())]);
    }

    /**
     * Statement of account PDF
     */
    public function statementPdf(ReceivablesRequest $request, CustomerAccount $customerAccount, BillingQueries $billing, BillingPdf $pdf): Response
    {
        Gate::authorize('viewBilling', $customerAccount);

        return $pdf->statement(
            $billing->statement($customerAccount, $request->from(), $request->to()),
            Organization::query()->findOrFail($customerAccount->organization_id),
        );
    }
}
