<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingBranch;
use App\Actions\Billing\BillingQueries;
use App\Actions\Billing\ManageInvoices;
use App\Actions\Fleet\FleetQueries;
use App\Actions\WorkOrders\WorkOrderQueries;
use App\Documents\BillingPdf;
use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\SaveInvoiceRequest;
use App\Http\Requests\VoidDocumentRequest;
use App\Http\Resources\InvoiceCollection;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Invoices (Phase 7): raised as drafts from closed jobs (or typed in),
 * issued with a number from the `invoice` series, paid through payments,
 * voided (never edited) once issued. Portal users see their own account's
 * issued invoices; staff those of the branches they work in. Core: no module.
 */
final class InvoiceController
{
    /**
     * List invoices
     *
     * `billing:view`. Drafts first, then newest issued. `status`: `draft`,
     * `issued`, `partially_paid`, `paid`, `void`, or `open` (issued or
     * partially paid) / `overdue` (open and past its due date).
     */
    public function index(ListInvoicesRequest $request, BillingQueries $billing): InvoiceCollection
    {
        Gate::authorize('viewAny', Invoice::class);

        return new InvoiceCollection($billing->invoicePage($request->filters(), $request->perPage()));
    }

    /**
     * Raise a draft invoice
     *
     * `billing:manage` (staff). Either `work_order_ids`: closed jobs of ONE
     * account and ONE branch, not yet invoiced (each approved line is billed
     * at its STORED approved cost: parts and labour as two lines, then each
     * job's flat fee); or `customer_account_id` + `lines` (+ `branch_id`) for
     * a typed-in invoice. Unnumbered until issued. A job already on another
     * invoice is 409.
     */
    public function store(SaveInvoiceRequest $request, ManageInvoices $invoices, FleetQueries $fleet, WorkOrderQueries $orders, BillingBranch $branches): JsonResponse
    {
        $ids = $request->workOrderIds();
        if ($ids !== []) {
            $found = $orders->orders()->whereIn('id', $ids)->get()->keyBy('id');
            $selected = [];
            foreach ($ids as $index => $id) {
                $order = $found->get($id);
                if (! $order instanceof WorkOrder) {
                    throw ValidationException::withMessages(["work_order_ids.{$index}" => 'No such work order.']);
                }
                Gate::authorize('view', $order);
                Gate::authorize('create', [Invoice::class, $fleet->account($order->customer_account_id), (string) $order->branch_id]);
                $selected[] = $order;
            }
            $invoice = $invoices->fromWorkOrders($selected, $request->notes());
        } else {
            $account = $fleet->account($request->accountId());
            $branchId = $branches->resolve($request->branchId());
            Gate::authorize('create', [Invoice::class, $account, $branchId]);
            $invoice = $invoices->manual($account, $branchId, $request->lines(), $request->notes());
        }

        return (new InvoiceResource($invoice))->response()->setStatusCode(201);
    }

    /**
     * Show an invoice
     */
    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return new InvoiceResource($invoice);
    }

    /**
     * Edit a draft
     *
     * `billing:manage`. `notes`; a typed-in invoice's `lines` (the whole
     * list); `discounts` (`line_id`, `discount_cents`) on any draft. Totals
     * are recomputed. An issued invoice never changes (409).
     */
    public function update(SaveInvoiceRequest $request, Invoice $invoice, ManageInvoices $invoices): InvoiceResource
    {
        Gate::authorize('update', $invoice);

        return new InvoiceResource($invoices->updateDraft($invoice, $request->draftChanges()));
    }

    /**
     * Discard a draft
     *
     * `billing:manage`. Only a draft (it has no number); its jobs may be
     * invoiced again. An issued invoice is voided instead.
     */
    public function destroy(Invoice $invoice, ManageInvoices $invoices): Response
    {
        Gate::authorize('update', $invoice);
        $invoices->discard($invoice);

        return response()->noContent();
    }

    /**
     * Issue an invoice
     *
     * `billing:manage`; requires an `Idempotency-Key`. Numbers the draft from
     * the organization's `invoice` series (`INV-YYYY-NNNN`) in this
     * transaction, freezes who it is from and to and its totals (VAT added or
     * extracted per the branch), dates it (today, or an earlier `issue_date`
     * that keeps the series in date order) and sets the due date from the
     * account's payment terms. Queues `invoice.issued`.
     */
    public function issue(IssueInvoiceRequest $request, Invoice $invoice, ManageInvoices $invoices): InvoiceResource
    {
        Gate::authorize('update', $invoice);

        return new InvoiceResource($invoices->issue($invoice, $request->issueDate()));
    }

    /**
     * Void an invoice
     *
     * `billing:void`, with a reason. Only an issued invoice nothing has been
     * paid against (void its payments first). It keeps its number; its jobs
     * may be invoiced again. Queues `invoice.voided`.
     */
    public function void(VoidDocumentRequest $request, Invoice $invoice, ManageInvoices $invoices): InvoiceResource
    {
        Gate::authorize('void', $invoice);

        return new InvoiceResource($invoices->void($invoice, $request->reason()));
    }

    /**
     * Invoice PDF
     *
     * The printed invoice (`application/pdf`), behind the same policy as the
     * invoice. A draft prints marked as not issued; a void one, as void.
     */
    public function pdf(Invoice $invoice, BillingPdf $pdf): Response
    {
        Gate::authorize('view', $invoice);

        return $pdf->invoice($invoice);
    }
}
