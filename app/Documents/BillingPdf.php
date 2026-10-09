<?php

declare(strict_types=1);

namespace App\Documents;

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\WebFormat;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/**
 * The printed billing documents (Phase 7): an invoice, a payment's
 * acknowledgment receipt, and a statement of account, rendered from Blade
 * (resources/views/pdf) by dompdf. Remote resources are never fetched.
 *
 * The wording is the shop's own and makes no claim of BIR accreditation:
 * whatever the branch's permit or series details are, they come from the
 * branch's `invoice_header` / `invoice_footer`, as its accountant words them.
 * A non-VAT branch's invoice carries Invoicing::NON_VAT_NOTICE; the
 * acknowledgment receipt and the statement say they are not invoices.
 *
 * TODO: confirm the invoice format with the accountant under the EOPT Act
 * (RA 11976) before go-live.
 */
final class BillingPdf
{
    public const string NOT_AN_INVOICE = 'This is not an invoice.';

    public function invoice(Invoice $invoice): Response
    {
        return $this->respond($this->invoiceHtml($invoice), ($invoice->number ?? 'draft-invoice').'.pdf');
    }

    public function payment(Payment $payment): Response
    {
        return $this->respond($this->paymentHtml($payment), $payment->number.'.pdf');
    }

    /**
     * @param  array<string, mixed>  $statement  BillingQueries::statement()
     */
    public function statement(array $statement, Organization $organization): Response
    {
        $name = sprintf('statement-%s-%s.pdf', is_string($statement['from'] ?? null) ? $statement['from'] : '', is_string($statement['to'] ?? null) ? $statement['to'] : '');

        return $this->respond($this->statementHtml($statement, $organization), $name);
    }

    public function invoiceHtml(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines']);
        $totals = $invoice->totals();
        $vatRegistered = $invoice->seller_vat_registered;
        $rate = (string) $invoice->vat_rate_pct->strippedOfTrailingZeros();

        $rows = [];
        if ($vatRegistered) {
            $rows[] = ['VATable sales', WebFormat::pesos($totals->vatableSalesCents)];
            $rows[] = ['VAT-exempt sales', WebFormat::pesos($totals->vatExemptSalesCents)];
            $rows[] = ['Zero-rated sales', WebFormat::pesos($totals->zeroRatedSalesCents)];
            $rows[] = ["VAT ({$rate}%)", WebFormat::pesos($totals->vatAmountCents)];
        } else {
            $rows[] = ['Total sales', WebFormat::pesos($totals->nonVatSalesCents)];
        }
        if ($totals->discountTotalCents > 0) {
            $rows[] = ['Discounts given (already deducted)', WebFormat::pesos($totals->discountTotalCents)];
        }

        return view('pdf.invoice', [
            'title' => $invoice->status === InvoiceStatus::Draft ? 'DRAFT INVOICE' : 'INVOICE',
            'invoice' => $invoice,
            'stamp' => match ($invoice->status) {
                InvoiceStatus::Draft => 'DRAFT — NOT ISSUED',
                InvoiceStatus::Void => 'VOID',
                default => null,
            },
            'tinLabel' => $vatRegistered ? 'VAT Reg. TIN' : 'Non-VAT Reg. TIN',
            'sellerTin' => self::tin($invoice->seller_tin, $invoice->seller_branch_code),
            'priceBasis' => $vatRegistered ? ($invoice->prices_include_vat ? 'Prices include VAT.' : 'Prices exclude VAT; VAT is added.') : null,
            'lines' => array_values($invoice->lines->map(fn (InvoiceLine $line): array => [
                'quantity' => Invoicing::quantity((string) $line->quantity),
                'description' => $line->description,
                'unit' => WebFormat::pesos($line->unit_price_cents),
                'discount' => $line->discount_cents > 0 ? WebFormat::pesos($line->discount_cents) : '',
                'amount' => WebFormat::pesos($line->line_total_cents),
                'tax' => match ($line->tax_class->value) {
                    'vat_exempt' => 'E',
                    'zero_rated' => 'Z',
                    default => $vatRegistered ? 'V' : '',
                },
            ])->all()),
            'totalRows' => $rows,
            'totalDue' => WebFormat::pesos($totals->totalDueCents),
            'nonVatNotice' => $vatRegistered ? null : Invoicing::NON_VAT_NOTICE,
            'date' => fn (?string $date): string => $date === null ? '—' : WebFormat::date($date),
        ])->render();
    }

    public function paymentHtml(Payment $payment): string
    {
        $payment->loadMissing(['allocations.invoice', 'customerAccount', 'branch']);
        $organization = Organization::query()->findOrFail($payment->organization_id);
        $branch = $payment->branch;
        $account = $payment->customerAccount;
        $posted = $payment->status === PaymentStatus::Posted;

        return view('pdf.payment', [
            'payment' => $payment,
            'stamp' => $posted ? null : 'VOID',
            'sellerName' => $branch->registered_name ?? $organization->legal_name ?? $organization->name,
            'sellerAddress' => $branch->address ?? $organization->address,
            'sellerTin' => self::tin($branch->tin ?? $organization->tin, $branch->branch_code),
            'buyerName' => $account->registered_name ?? $account->display_name,
            'buyerTin' => $account->tin,
            'buyerAddress' => $account->address,
            'amount' => WebFormat::pesos($payment->amount_cents),
            'allocations' => array_values($payment->allocations->map(fn (PaymentAllocation $a): array => [
                'invoice' => (string) $a->invoice->number,
                'date' => WebFormat::date($a->allocated_on->toDateString()),
                'amount' => WebFormat::pesos($a->amount_cents),
            ])->all()),
            'credit' => $posted && $payment->unallocatedCents() > 0 ? WebFormat::pesos($payment->unallocatedCents()) : null,
            'receivedOn' => WebFormat::date($payment->received_on->toDateString()),
            'notInvoice' => self::NOT_AN_INVOICE,
            'inputTaxNotice' => Invoicing::NON_VAT_NOTICE,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    public function statementHtml(array $statement, Organization $organization): string
    {
        $text = fn (string $key): string => is_string($statement[$key] ?? null) ? $statement[$key] : '';
        $money = fn (string $key): string => WebFormat::pesos(is_int($statement[$key] ?? null) ? $statement[$key] : 0);
        $entries = is_array($statement['entries'] ?? null) ? $statement['entries'] : [];

        return view('pdf.statement', [
            'sellerName' => $organization->legal_name ?? $organization->name,
            'sellerAddress' => $organization->address,
            'sellerTin' => $organization->tin,
            'customerName' => $text('customer_name'),
            'customerTin' => $statement['customer_tin'] ?? null,
            'customerAddress' => $statement['customer_address'] ?? null,
            'from' => WebFormat::date($text('from')),
            'to' => WebFormat::date($text('to')),
            'opening' => $money('opening_balance_cents'),
            'charges' => $money('total_charges_cents'),
            'credits' => $money('total_credits_cents'),
            'closing' => $money('closing_balance_cents'),
            'entries' => array_map(fn (mixed $row): array => [
                'date' => WebFormat::date(self::field($row, 'date')),
                'reference' => self::field($row, 'reference'),
                'description' => self::field($row, 'description'),
                'charge' => self::cents($row, 'charge_cents') > 0 ? WebFormat::pesos(self::cents($row, 'charge_cents')) : '',
                'credit' => self::cents($row, 'credit_cents') > 0 ? WebFormat::pesos(self::cents($row, 'credit_cents')) : '',
                'balance' => WebFormat::pesos(self::cents($row, 'balance_cents')),
            ], $entries),
            'notInvoice' => self::NOT_AN_INVOICE,
        ])->render();
    }

    /** `123-456-789-00000`: the TIN with the branch code, as BIR documents print it. */
    private static function tin(?string $tin, ?string $branchCode): ?string
    {
        if ($tin === null) {
            return null;
        }

        return $branchCode === null ? $tin : $tin.'-'.str_pad($branchCode, 5, '0', STR_PAD_LEFT);
    }

    private static function field(mixed $row, string $key): string
    {
        return is_array($row) && is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    private static function cents(mixed $row, string $key): int
    {
        return is_array($row) && is_int($row[$key] ?? null) ? $row[$key] : 0;
    }

    private function respond(string $html, string $filename): Response
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(sys_get_temp_dir());

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return new Response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', preg_replace('/[^A-Za-z0-9._-]/', '_', $filename)),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
