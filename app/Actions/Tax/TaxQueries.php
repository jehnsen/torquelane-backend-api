<?php

declare(strict_types=1);

namespace App\Actions\Tax;

use App\Actions\Ledger\PostingRules;
use App\Database\Cell;
use App\Domain\Ledger\RuleKey;
use App\Domain\Shared\BusinessCalendar;
use App\Domain\Shared\Calendar;
use App\Domain\Tax\Quarter;
use App\Domain\Tax\TaxableDocument;
use App\Domain\Tax\VatReturn;
use App\Domain\Tax\WithheldAmount;
use App\Domain\Tax\WithholdingSummary;
use App\Models\Branch;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PrepaidPackage;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\WithholdingCertificate;
use App\Tenancy\TenantManager;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind the BIR returns, read off the documents themselves and
 * always over the branches the caller works in (a branch is a BIR
 * registration: its own TIN and branch code). Nothing here files a return or
 * claims a format BIR has accredited: these are the working papers.
 *
 * Every figure follows the books' own dating: a document counts on its
 * business date, and a void or a credit note counts as a negative on the day
 * it happened, never by rewriting the period it reverses. So a period's VAT
 * here is the movement of the Output and Input VAT accounts for that period
 * (`ledger` in the VAT answer shows both, to prove it).
 *
 * TODO: confirm each layout with the accountant against the current BIR forms
 * (2550Q, the SLS / SLP, 1601-EQ and its QAP, the SAWT, 2307) before go-live.
 */
final class TaxQueries
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly PostingRules $rules,
    ) {}

    /**
     * The VAT position of a period: sales and output VAT, purchases and input
     * VAT, and what is payable.
     *
     * @return array<string, mixed>
     */
    public function vat(string $from, string $to): array
    {
        return ['from' => $from, 'to' => $to, 'scope' => $this->scope()]
            + VatReturn::summarize($this->sales($from, $to), $this->purchases($from, $to))
            + ['ledger' => [
                'output_vat_cents' => -$this->movement(RuleKey::OutputVat, $from, $to),
                'input_vat_cents' => $this->movement(RuleKey::InputVat, $from, $to),
            ]];
    }

    /**
     * Summary list of sales: one row per customer.
     *
     * @return array<string, mixed>
     */
    public function salesList(string $from, string $to): array
    {
        return ['from' => $from, 'to' => $to, 'scope' => $this->scope()] + VatReturn::summaryList($this->sales($from, $to));
    }

    /**
     * Summary list of purchases: one row per vendor.
     *
     * @return array<string, mixed>
     */
    public function purchasesList(string $from, string $to): array
    {
        return ['from' => $from, 'to' => $to, 'scope' => $this->scope()] + VatReturn::summaryList($this->purchases($from, $to));
    }

    /**
     * What the shop withheld from vendors (expanded withholding tax): the
     * alphalist of payees behind form 1601-EQ.
     *
     * @return array<string, mixed>
     */
    public function withheldFromVendors(string $from, string $to): array
    {
        return ['from' => $from, 'to' => $to, 'scope' => $this->scope()] + WithholdingSummary::alphalist($this->vendorWithholding($from, $to, null));
    }

    /**
     * What customers withheld from the shop (their 2307s): the summary
     * alphalist of withholding taxes that supports the tax credit.
     *
     * @return array<string, mixed>
     */
    public function withheldByCustomers(string $from, string $to): array
    {
        $amounts = [];
        $certificates = WithholdingCertificate::query()
            ->with('customerAccount')
            ->whereIn('branch_id', $this->branchIds())
            ->where(fn (Builder $q) => $q->whereBetween('received_on', [$from, $to])->orWhereNotNull('voided_at'))
            ->orderBy('id')->get();
        foreach ($certificates as $certificate) {
            $account = $certificate->customerAccount;
            $amount = new WithheldAmount(
                $certificate->received_on->toDateString(),
                $account->id,
                $account->registered_name ?? $account->display_name,
                $account->tin,
                $account->address,
                $certificate->atc_code,
                (string) $certificate->rate_pct->strippedOfTrailingZeros(),
                $certificate->tax_base_cents,
                $certificate->amount_cents,
                $certificate->certificate_no ?? '',
            );
            if ($amount->date >= $from && $amount->date <= $to) {
                $amounts[] = $amount;
            }
            $voidedOn = $certificate->voided_at === null ? null : Calendar::toDate($certificate->voided_at);
            if ($voidedOn !== null && $voidedOn >= $from && $voidedOn <= $to) {
                $amounts[] = new WithheldAmount($voidedOn, $amount->partyId, $amount->partyName, $amount->partyTin, $amount->partyAddress, $amount->atcCode, $amount->ratePct, -$amount->taxBaseCents, -$amount->taxWithheldCents, $amount->reference);
            }
        }

        return ['from' => $from, 'to' => $to, 'scope' => $this->scope()] + WithholdingSummary::alphalist($amounts);
    }

    /**
     * One vendor's quarter as BIR form 2307 lays it out: who withheld, from
     * whom, and per ATC the income payments of each month of the quarter with
     * the tax withheld. The certificate the shop hands the vendor.
     *
     * @return array<string, mixed>
     */
    public function certificate(Vendor $vendor, Quarter $quarter): array
    {
        $context = $this->tenancy->require();
        $organization = Organization::query()->findOrFail($context->organizationId());
        // A certificate is one registration's: the branch selected, else the organization's own details.
        $branch = $context->selectedBranchId === null ? null : Branch::query()->find($context->selectedBranchId);
        $amounts = $this->vendorWithholding($quarter->startsOn(), $quarter->endsOn(), $vendor->id);

        return [
            'form' => '2307',
            'period' => ['year' => $quarter->year, 'quarter' => $quarter->quarter, 'from' => $quarter->startsOn(), 'to' => $quarter->endsOn(), 'label' => $quarter->label()],
            'payee' => [
                'vendor_id' => $vendor->id,
                'name' => $vendor->registered_name ?? $vendor->name,
                'tin' => $vendor->tin,
                'address' => $vendor->address,
            ],
            'payor' => [
                'name' => $branch->registered_name ?? $organization->legal_name ?? $organization->name,
                'tin' => $branch->tin ?? $organization->tin,
                'branch_code' => $branch?->branch_code,
                'address' => $branch->address ?? $organization->address,
            ],
            'scope' => $this->scope(),
            'documents' => array_map(fn (WithheldAmount $amount): array => [
                'date' => $amount->date,
                'reference' => $amount->reference,
                'atc_code' => $amount->atcCode,
                'tax_base_cents' => $amount->taxBaseCents,
                'tax_withheld_cents' => $amount->taxWithheldCents,
            ], $amounts),
        ] + WithholdingSummary::certificate($amounts, $quarter);
    }

    /**
     * Sales documents counted in [from, to]: invoices on their issue date,
     * prepaid packages on the day sold (the sale is the taxable event), credit
     * notes as negatives on their own date, and each void as a reversal on
     * the day it was made.
     *
     * @return list<TaxableDocument>
     */
    public function sales(string $from, string $to): array
    {
        $branches = $this->branchIds();
        [$start, $end] = $this->instants($from, $to);
        $documents = [];

        $invoices = Invoice::query()
            ->whereIn('branch_id', $branches)->where('status', '<>', 'draft')
            ->where(fn (Builder $q) => $q->whereBetween('issue_date', [$from, $to])->orWhere(fn (Builder $v) => $v->where('voided_at', '>=', $start)->where('voided_at', '<', $end)))
            ->orderBy('issue_date')->orderBy('number')->get();
        foreach ($invoices as $invoice) {
            $document = new TaxableDocument(
                (string) $invoice->issue_date?->toDateString(), 'invoice', (string) $invoice->number, $invoice->customer_account_id,
                $invoice->buyer_name, $invoice->buyer_tin, $invoice->buyer_address,
                $invoice->vatable_sales_cents, $invoice->vat_exempt_sales_cents, $invoice->zero_rated_sales_cents, $invoice->non_vat_sales_cents, $invoice->vat_amount_cents,
            );
            $this->counted($documents, $document, $invoice->voided_at === null ? null : Calendar::toDate($invoice->voided_at), 'invoice_void', $from, $to);
        }

        $notes = CreditNote::query()
            ->with('invoice')
            ->whereIn('branch_id', $branches)
            ->where(fn (Builder $q) => $q->whereBetween('issued_on', [$from, $to])->orWhere(fn (Builder $v) => $v->where('voided_at', '>=', $start)->where('voided_at', '<', $end)))
            ->orderBy('issued_on')->orderBy('number')->get();
        foreach ($notes as $note) {
            $document = new TaxableDocument(
                $note->issued_on->toDateString(), 'credit_note', $note->number, $note->customer_account_id,
                $note->invoice->buyer_name, $note->invoice->buyer_tin, $note->invoice->buyer_address,
                -$note->vatable_sales_cents, -$note->vat_exempt_sales_cents, -$note->zero_rated_sales_cents, -$note->non_vat_sales_cents, -$note->vat_amount_cents,
            );
            $this->counted($documents, $document, $note->voided_at === null ? null : Calendar::toDate($note->voided_at), 'credit_note_void', $from, $to);
        }

        $packages = PrepaidPackage::query()->with('customerAccount')->whereIn('branch_id', $branches)->whereBetween('sold_on', [$from, $to])->orderBy('sold_on')->orderBy('reference')->get();
        foreach ($packages as $package) {
            $account = $package->customerAccount;
            $vatable = $package->vat_cents > 0;
            $documents[] = new TaxableDocument(
                $package->sold_on->toDateString(), 'prepaid_sale', $package->reference, $account->id,
                $account->registered_name ?? $account->display_name, $account->tin, $account->address,
                $vatable ? $package->netPriceCents() : 0, 0, 0, $vatable ? 0 : $package->price_cents, $package->vat_cents,
            );
        }

        usort($documents, fn (TaxableDocument $a, TaxableDocument $b): int => [$a->date, $a->reference, $a->kind] <=> [$b->date, $b->reference, $b->kind]);

        return $documents;
    }

    /**
     * Vendor bills counted in [from, to] on their bill date, and each void as
     * a reversal on the day it was made.
     *
     * @return list<TaxableDocument>
     */
    public function purchases(string $from, string $to): array
    {
        [$start, $end] = $this->instants($from, $to);
        $documents = [];
        $bills = VendorBill::query()
            ->whereIn('branch_id', $this->branchIds())
            ->where(fn (Builder $q) => $q->whereBetween('bill_date', [$from, $to])->orWhere(fn (Builder $v) => $v->where('voided_at', '>=', $start)->where('voided_at', '<', $end)))
            ->orderBy('bill_date')->orderBy('number')->get();
        foreach ($bills as $bill) {
            $document = new TaxableDocument(
                $bill->bill_date->toDateString(), 'vendor_bill', "{$bill->number} ({$bill->vendor_invoice_no})", $bill->vendor_id,
                $bill->vendor_name, $bill->vendor_tin, $bill->vendor_address,
                $bill->vatable_purchases_cents, $bill->vat_exempt_purchases_cents, $bill->zero_rated_purchases_cents, $bill->non_vat_purchases_cents, $bill->input_vat_cents,
            );
            $this->counted($documents, $document, $bill->voided_at === null ? null : Calendar::toDate($bill->voided_at), 'vendor_bill_void', $from, $to);
        }
        usort($documents, fn (TaxableDocument $a, TaxableDocument $b): int => [$a->date, $a->reference, $a->kind] <=> [$b->date, $b->reference, $b->kind]);

        return $documents;
    }

    /**
     * @return array{branches: list<array{id: string, name: string, tin: string|null, branch_code: string|null}>, all_branches: bool}
     */
    public function scope(): array
    {
        $context = $this->tenancy->require();
        $branches = Branch::query()->whereIn('id', $this->branchIds())->orderBy('name')->get();

        return [
            'branches' => array_values($branches->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name, 'tin' => $branch->tin, 'branch_code' => $branch->branch_code])->all()),
            'all_branches' => ! $context->branchRestricted && $context->selectedBranchId === null,
        ];
    }

    /**
     * @return list<WithheldAmount>
     */
    private function vendorWithholding(string $from, string $to, ?string $vendorId): array
    {
        [$start, $end] = $this->instants($from, $to);
        $bills = VendorBill::query()
            ->whereIn('branch_id', $this->branchIds())
            ->whereNotNull('atc_code')->where('withholding_cents', '>', 0)
            ->when($vendorId !== null, fn (Builder $q) => $q->where('vendor_id', $vendorId))
            ->where(fn (Builder $q) => $q->whereBetween('bill_date', [$from, $to])->orWhere(fn (Builder $v) => $v->where('voided_at', '>=', $start)->where('voided_at', '<', $end)))
            ->orderBy('bill_date')->orderBy('number')->get();

        $amounts = [];
        foreach ($bills as $bill) {
            $amount = new WithheldAmount(
                $bill->bill_date->toDateString(), $bill->vendor_id, $bill->vendor_name, $bill->vendor_tin, $bill->vendor_address,
                (string) $bill->atc_code, (string) $bill->withholding_rate_pct?->strippedOfTrailingZeros(),
                $bill->withholding_base_cents, $bill->withholding_cents, $bill->number,
            );
            if ($amount->date >= $from && $amount->date <= $to) {
                $amounts[] = $amount;
            }
            $voidedOn = $bill->voided_at === null ? null : Calendar::toDate($bill->voided_at);
            if ($voidedOn !== null && $voidedOn >= $from && $voidedOn <= $to) {
                $amounts[] = new WithheldAmount($voidedOn, $amount->partyId, $amount->partyName, $amount->partyTin, $amount->partyAddress, $amount->atcCode, $amount->ratePct, -$amount->taxBaseCents, -$amount->taxWithheldCents, $amount->reference);
            }
        }

        return $amounts;
    }

    /**
     * Adds a document if it is dated in the range, and its reversal if it was voided in the range.
     *
     * @param  list<TaxableDocument>  $documents
     */
    private function counted(array &$documents, TaxableDocument $document, ?string $voidedOn, string $voidKind, string $from, string $to): void
    {
        if ($document->date >= $from && $document->date <= $to) {
            $documents[] = $document;
        }
        if ($voidedOn !== null && $voidedOn >= $from && $voidedOn <= $to) {
            $documents[] = $document->reversed($voidedOn, $voidKind);
        }
    }

    /** Debits less credits on a rule's account over the range, within the caller's branches. */
    private function movement(RuleKey $key, string $from, string $to): int
    {
        $organizationId = $this->tenancy->require()->organizationId();
        $row = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.organization_id', $organizationId)
            ->where('l.account_id', $this->rules->accountId($organizationId, $key))
            ->whereIn('l.branch_id', $this->branchIds())
            ->whereBetween('e.entry_date', [$from, $to])
            ->selectRaw('coalesce(sum(l.debit_cents), 0) as debits, coalesce(sum(l.credit_cents), 0) as credits')
            ->first();

        return Cell::int($row->debits ?? null) - Cell::int($row->credits ?? null);
    }

    /**
     * The range as instants: the start of its first Manila day, and of the day after its last.
     *
     * @return array{0: DateTimeInterface, 1: DateTimeInterface}
     */
    private function instants(string $from, string $to): array
    {
        return [
            BusinessCalendar::startOfDayUtc($from),
            BusinessCalendar::startOfDayUtc(Calendar::toDate(Calendar::addDays(Calendar::parseDate($to), 1))),
        ];
    }

    /**
     * @return list<string>
     */
    private function branchIds(): array
    {
        return $this->tenancy->require()->branchFilter();
    }
}
