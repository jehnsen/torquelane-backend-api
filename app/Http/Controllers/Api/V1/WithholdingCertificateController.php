<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingQueries;
use App\Actions\Billing\ManageWithholding;
use App\Http\Requests\ListSettlementsRequest;
use App\Http\Requests\RecordWithholdingRequest;
use App\Http\Requests\VoidDocumentRequest;
use App\Http\Resources\WithholdingCertificateCollection;
use App\Http\Resources\WithholdingCertificateResource;
use App\Models\Invoice;
use App\Models\WithholdingCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Withholding certificates received (Phase 12): the BIR 2307s customers hand
 * over for tax they withheld at source from an invoice.
 */
final class WithholdingCertificateController
{
    /**
     * List withholding certificates received
     *
     * `billing:view`. Newest first; `status` `posted` or `void`,
     * `customer_account_id`, `branch_id`, `invoice_id`.
     */
    public function index(ListSettlementsRequest $request, BillingQueries $billing): WithholdingCertificateCollection
    {
        Gate::authorize('viewAny', WithholdingCertificate::class);

        return new WithholdingCertificateCollection($billing->settlementPage($billing->withholdingCertificates()->with(['invoice', 'customerAccount']), $request->filters(), 'received_on', $request->perPage()));
    }

    /**
     * Record tax withheld from an invoice
     *
     * `billing:manage` (staff). `atc_code` (like WC160), `rate_pct`, and
     * optionally `tax_base_cents` (default: the invoice's sales before VAT),
     * `certificate_no`, `received_on` (default today) and the period the
     * 2307 covers (default: the quarter the invoice was issued in). The
     * amount, round(base × rate / 100), comes off the invoice's balance and
     * never exceeds it. Books Dr Creditable Withholding Tax / Cr Accounts
     * Receivable.
     */
    public function store(RecordWithholdingRequest $request, Invoice $invoice, ManageWithholding $withholding): JsonResponse
    {
        Gate::authorize('create', [WithholdingCertificate::class, $invoice]);

        return (new WithholdingCertificateResource($withholding->record($invoice, $request->certificate())))->response()->setStatusCode(201);
    }

    /**
     * Show a withholding certificate
     */
    public function show(WithholdingCertificate $withholding_certificate): WithholdingCertificateResource
    {
        Gate::authorize('view', $withholding_certificate);

        return new WithholdingCertificateResource($withholding_certificate);
    }

    /**
     * Void a withholding certificate
     *
     * `billing:void`, with a reason. The invoice owes the amount again.
     */
    public function void(VoidDocumentRequest $request, WithholdingCertificate $withholding_certificate, ManageWithholding $withholding): WithholdingCertificateResource
    {
        Gate::authorize('void', $withholding_certificate);

        return new WithholdingCertificateResource($withholding->void($withholding_certificate, $request->reason()));
    }
}
