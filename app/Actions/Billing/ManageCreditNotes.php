<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Audit\AuditTrail;
use App\Actions\Ledger\LedgerPostings;
use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Billing\Billing;
use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\InvoiceLineDraft;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Numbering\DocumentType;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\WebFormat;
use App\Exceptions\InvalidTransitionException;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Refund;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credit notes: how an issued invoice is corrected once voiding it is no
 * longer the right answer (it is paid, or part of it stands).
 *
 * issue: against ONE standing invoice, numbered from the `credit_note` series
 * in this transaction (R8), immutable from then on (R7). A line credits some
 * or all of an invoice line (never more than that line billed, across every
 * standing note), or is an allowance on the invoice as a whole. The totals
 * are worked out under the INVOICE's own VAT treatment, so a credit undoes a
 * sale the way it was made. The notes on an invoice never exceed its total.
 *
 * Where the money goes: the note comes off the invoice's balance as far as
 * the invoice still owed (`applied_cents`); anything beyond that (the invoice
 * was already paid) is the customer's credit (`credit_cents`), held in
 * Customer Deposits until a refund pays it out.
 *
 * void: only while none of its credit has been refunded. The invoice owes
 * again what the note had taken off it.
 *
 * Locks: the invoice, then the credit note.
 *
 * @phpstan-type CreditLineInput array{invoice_line_id?: string|null, description?: string|null, quantity?: string|null, unit_price_cents?: int|null, tax_class?: string|null}
 */
final class ManageCreditNotes
{
    public function __construct(
        private readonly BillingJournal $journal,
        private readonly InvoiceSettlement $settlement,
        private readonly DocumentNumbers $numbers,
        private readonly LedgerPostings $postings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{reason: string, issued_on?: string|null, lines: list<CreditLineInput>}  $data  validated
     */
    public function issue(Invoice $invoice, array $data): CreditNote
    {
        return DB::transaction(function () use ($invoice, $data): CreditNote {
            $locked = $this->journal->lockInvoice($invoice);
            if (! $locked->status->isStanding()) {
                throw new InvalidTransitionException(sprintf('Only an issued invoice is credited; this one is %s.', $locked->status->value));
            }

            $now = CarbonImmutable::now();
            $today = Calendar::toDate($now);
            $issuedOn = $data['issued_on'] ?? $today;
            if ($issuedOn > $today) {
                throw ValidationException::withMessages(['issued_on' => 'A credit note is never dated in the future.']);
            }
            if ($issuedOn < (string) $locked->issue_date?->toDateString()) {
                throw ValidationException::withMessages(['issued_on' => "A credit note is not dated before invoice {$locked->number} was issued."]);
            }

            $drafts = $this->drafts($locked, $data['lines']);
            $totals = Invoicing::totals(array_map(fn (array $draft): InvoiceLineDraft => $draft['line'], $drafts), $locked->vat());
            if ($totals->totalDueCents <= 0) {
                throw ValidationException::withMessages(['lines' => 'A credit note is for more than nothing.']);
            }
            $already = (int) CreditNote::query()->where('invoice_id', $locked->id)->where('status', CreditNote::ISSUED)->sum('total_cents');
            if ($already + $totals->totalDueCents > $locked->total_due_cents) {
                throw ValidationException::withMessages(['lines' => sprintf('Invoice %s is for %s and %s of it is already credited; this note would credit more than it billed.', $locked->number, WebFormat::pesos($locked->total_due_cents), WebFormat::pesos($already))]);
            }

            // Off the invoice as far as it still owes; the rest is credit the customer holds.
            $applied = min($totals->totalDueCents, $locked->balanceCents());
            $actor = $this->journal->actor();

            $note = new CreditNote;
            $note->forceFill([
                'branch_id' => $locked->branch_id,
                'customer_account_id' => $locked->customer_account_id,
                'invoice_id' => $locked->id,
                'number' => $this->numbers->issue($locked->organization_id, null, DocumentType::CreditNote, $now)->formatted,
                'status' => CreditNote::ISSUED,
                'issued_on' => $issuedOn,
                'reason' => $data['reason'],
                'seller_vat_registered' => $locked->seller_vat_registered,
                'prices_include_vat' => $locked->prices_include_vat,
                'vat_rate_pct' => (string) $locked->vat_rate_pct,
                'vatable_sales_cents' => $totals->vatableSalesCents,
                'vat_exempt_sales_cents' => $totals->vatExemptSalesCents,
                'zero_rated_sales_cents' => $totals->zeroRatedSalesCents,
                'non_vat_sales_cents' => $totals->nonVatSalesCents,
                'vat_amount_cents' => $totals->vatAmountCents,
                'total_cents' => $totals->totalDueCents,
                'applied_cents' => $applied,
                'credit_cents' => $totals->totalDueCents - $applied,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            foreach ($drafts as $position => $draft) {
                $line = new CreditNoteLine;
                $line->forceFill([
                    'credit_note_id' => $note->id,
                    'position' => $position,
                    'invoice_line_id' => $draft['invoice_line_id'],
                    'description' => $draft['line']->description,
                    'quantity' => $draft['line']->quantity,
                    'unit_price_cents' => $draft['line']->unitPriceCents,
                    'tax_class' => $draft['line']->taxClass,
                    'line_total_cents' => $draft['line']->totalCents(),
                ])->save();
            }

            $this->settlement->refresh($locked, $now);
            $note->setRelation('invoice', $locked);
            $this->postings->creditNoteIssued($note);
            $this->audit->record($note, 'issued', null, self::snapshot($note));

            return $note->load(['lines', 'refunds']);
        });
    }

    public function void(CreditNote $note, string $reason): CreditNote
    {
        return DB::transaction(function () use ($note, $reason): CreditNote {
            $invoice = $this->journal->lockInvoice(Invoice::query()->findOrFail($note->invoice_id));
            $locked = CreditNote::query()->lockForUpdate()->findOrFail($note->id);
            if (! $locked->isStanding()) {
                throw new InvalidTransitionException("Credit note {$locked->number} is already void.");
            }
            if (Refund::query()->where('credit_note_id', $locked->id)->where('status', PaymentStatus::Posted->value)->exists()) {
                throw new InvalidTransitionException("Credit note {$locked->number} has been refunded; void the refund first.");
            }

            $before = self::snapshot($locked);
            $locked->forceFill([
                'status' => CreditNote::VOID,
                'voided_at' => CarbonImmutable::now(),
                'voided_by_name' => $this->journal->actor()->name,
                'void_reason' => $reason,
            ])->save();
            $this->settlement->refresh($invoice);
            $this->postings->creditNoteVoided($locked, $reason);
            $this->audit->record($locked, 'voided', $before, self::snapshot($locked));

            return $locked->load(['lines', 'refunds', 'invoice']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(CreditNote $note): array
    {
        return AuditTrail::snapshot($note) + [
            'lines' => array_values($note->lines()->get()->map(fn (CreditNoteLine $line): array => AuditTrail::snapshot($line))->all()),
        ];
    }

    /**
     * Each requested line against the invoice line it credits (if any) and
     * what earlier standing notes already took off that line.
     *
     * @param  list<CreditLineInput>  $lines
     * @return list<array{line: InvoiceLineDraft, invoice_line_id: string|null}>
     */
    private function drafts(Invoice $invoice, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A credit note has at least one line.']);
        }

        $invoiceLines = $invoice->lines()->get()->keyBy('id');
        /** @var array<string, int> $credited invoice line id → centavos already credited */
        $credited = [];
        $rows = CreditNoteLine::query()
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->where('credit_notes.invoice_id', $invoice->id)
            ->where('credit_notes.status', CreditNote::ISSUED)
            ->whereNotNull('credit_note_lines.invoice_line_id')
            ->get(['credit_note_lines.invoice_line_id', 'credit_note_lines.line_total_cents']);
        foreach ($rows as $row) {
            $credited[(string) $row->invoice_line_id] = ($credited[(string) $row->invoice_line_id] ?? 0) + $row->line_total_cents;
        }

        $drafts = [];
        foreach ($lines as $position => $input) {
            $field = "lines.{$position}";
            $lineId = $input['invoice_line_id'] ?? null;

            if ($lineId === null) {
                $description = trim((string) ($input['description'] ?? ''));
                if ($description === '' || ! isset($input['unit_price_cents'])) {
                    throw ValidationException::withMessages([$field => 'An allowance needs a description and an amount.']);
                }
                $drafts[] = [
                    'line' => new InvoiceLineDraft(InvoiceLineKind::Manual, $description, $input['quantity'] ?? '1', $input['unit_price_cents'], 0, TaxClass::from($input['tax_class'] ?? TaxClass::Vatable->value)),
                    'invoice_line_id' => null,
                ];

                continue;
            }

            $invoiceLine = $invoiceLines->get($lineId);
            if (! $invoiceLine instanceof InvoiceLine) {
                throw ValidationException::withMessages(["{$field}.invoice_line_id" => "That line is not on invoice {$invoice->number}."]);
            }
            $left = $invoiceLine->line_total_cents - ($credited[$lineId] ?? 0);
            // Nothing said: credit what is left of the line. A quantity alone: at the line's own price.
            if (! isset($input['quantity']) && ! isset($input['unit_price_cents'])) {
                $quantity = '1';
                $price = $left;
            } else {
                $quantity = $input['quantity'] ?? '1';
                $price = $input['unit_price_cents'] ?? $invoiceLine->unit_price_cents;
            }
            $amount = Billing::roundCents(BigDecimal::of($quantity)->multipliedBy($price));
            if ($amount <= 0) {
                throw ValidationException::withMessages([$field => sprintf('"%s" has nothing left to credit.', $invoiceLine->description)]);
            }
            if ($amount > $left) {
                throw ValidationException::withMessages([$field => sprintf('Only %s of "%s" is left to credit.', WebFormat::pesos($left), $invoiceLine->description)]);
            }
            $credited[$lineId] = ($credited[$lineId] ?? 0) + $amount;
            $drafts[] = [
                'line' => new InvoiceLineDraft(InvoiceLineKind::Manual, trim((string) ($input['description'] ?? '')) ?: $invoiceLine->description, $quantity, $price, 0, $invoiceLine->tax_class),
                'invoice_line_id' => $invoiceLine->id,
            ];
        }

        return $drafts;
    }
}
