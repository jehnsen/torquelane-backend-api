<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Audit\AuditTrail;
use App\Actions\Ledger\LedgerPostings;
use App\Domain\Payables\Payables;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\WebFormat;
use App\Domain\Tax\Atc;
use App\Domain\Tax\Quarter;
use App\Exceptions\InvalidTransitionException;
use App\Models\Invoice;
use App\Models\WithholdingCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tax a customer withheld at source (creditable withholding, BIR form 2307).
 * A customer who is a withholding agent pays an invoice short by the tax and
 * hands over a 2307 for it. Recording the certificate takes that amount off
 * the invoice (it settles like money) and books it as a tax credit of the
 * shop's: Dr Creditable Withholding Tax / Cr Accounts Receivable.
 *
 * The amount is worked out here: round(tax base × rate / 100), the base
 * being the invoice's sales before VAT unless a smaller one is given (part of
 * the invoice was not subject to withholding). It never exceeds what the
 * invoice still owes.
 *
 * void: R7's reversal; the invoice owes the amount again.
 *
 * Locks: the invoice, then the certificate.
 */
final class ManageWithholding
{
    public function __construct(
        private readonly BillingJournal $journal,
        private readonly InvoiceSettlement $settlement,
        private readonly LedgerPostings $postings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{atc_code: string, rate_pct: string, tax_base_cents?: int|null, certificate_no?: string|null, received_on?: string|null, period_from?: string|null, period_to?: string|null, notes?: string|null}  $data  validated
     */
    public function record(Invoice $invoice, array $data): WithholdingCertificate
    {
        return DB::transaction(function () use ($invoice, $data): WithholdingCertificate {
            $locked = $this->journal->lockInvoice($invoice);
            if (! $locked->status->isOpen()) {
                throw new InvalidTransitionException(sprintf('Withholding comes off an invoice that is still owed; %s is %s.', $locked->number ?? 'this one', $locked->status->value));
            }

            $code = strtoupper(trim($data['atc_code']));
            if (! Atc::isValid($code)) {
                throw ValidationException::withMessages(['atc_code' => 'An ATC reads like WC158 or WI160.']);
            }

            $now = CarbonImmutable::now();
            $today = Calendar::toDate($now);
            $issued = (string) $locked->issue_date?->toDateString();
            $receivedOn = $data['received_on'] ?? $today;
            if ($receivedOn > $today) {
                throw ValidationException::withMessages(['received_on' => 'A certificate is never dated in the future.']);
            }
            if ($receivedOn < $issued) {
                throw ValidationException::withMessages(['received_on' => "A certificate is not dated before invoice {$locked->number} was issued."]);
            }

            $netSales = $locked->totals()->netSalesCents();
            $base = $data['tax_base_cents'] ?? $netSales;
            if ($base > $netSales) {
                throw ValidationException::withMessages(['tax_base_cents' => sprintf('Invoice %s sold %s before VAT; tax is not withheld on more than that.', $locked->number, WebFormat::pesos($netSales))]);
            }
            $amount = Payables::withholding($base, $data['rate_pct']);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['rate_pct' => 'That rate withholds nothing on this invoice.']);
            }
            if ($amount > $locked->balanceCents()) {
                throw ValidationException::withMessages(['tax_base_cents' => sprintf('Invoice %s has only %s outstanding; %s cannot be withheld from it.', $locked->number, WebFormat::pesos($locked->balanceCents()), WebFormat::pesos($amount))]);
            }

            // A 2307 covers a quarter: by default, the one the invoice was issued in.
            $quarter = Quarter::of($issued);
            $from = $data['period_from'] ?? $quarter->startsOn();
            $to = $data['period_to'] ?? $quarter->endsOn();
            if ($to < $from) {
                throw ValidationException::withMessages(['period_to' => 'The period ends before it starts.']);
            }

            $actor = $this->journal->actor();
            $certificate = new WithholdingCertificate;
            $certificate->forceFill([
                'branch_id' => $locked->branch_id,
                'customer_account_id' => $locked->customer_account_id,
                'invoice_id' => $locked->id,
                'status' => PaymentStatus::Posted,
                'certificate_no' => $data['certificate_no'] ?? null,
                'atc_code' => $code,
                'rate_pct' => $data['rate_pct'],
                'tax_base_cents' => $base,
                'amount_cents' => $amount,
                'received_on' => $receivedOn,
                'period_from' => $from,
                'period_to' => $to,
                'notes' => trim((string) ($data['notes'] ?? '')),
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->settlement->refresh($locked, $now);
            $certificate->setRelation('invoice', $locked);
            $this->postings->withholdingReceived($certificate);
            $this->audit->record($certificate, 'recorded', null, AuditTrail::snapshot($certificate));

            return $certificate->load('customerAccount');
        });
    }

    public function void(WithholdingCertificate $certificate, string $reason): WithholdingCertificate
    {
        return DB::transaction(function () use ($certificate, $reason): WithholdingCertificate {
            $invoice = $this->journal->lockInvoice(Invoice::query()->findOrFail($certificate->invoice_id));
            $locked = WithholdingCertificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if (! $locked->isStanding()) {
                throw new InvalidTransitionException('That certificate is already void.');
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill([
                'status' => PaymentStatus::Void,
                'voided_at' => CarbonImmutable::now(),
                'voided_by_name' => $this->journal->actor()->name,
                'void_reason' => $reason,
            ])->save();
            $this->settlement->refresh($invoice);
            $this->postings->withholdingVoided($locked, $reason);
            $this->audit->record($locked, 'voided', $before, AuditTrail::snapshot($locked));

            return $locked->load(['invoice', 'customerAccount']);
        });
    }
}
