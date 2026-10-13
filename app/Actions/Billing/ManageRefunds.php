<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Audit\AuditTrail;
use App\Actions\Ledger\LedgerPostings;
use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Numbering\DocumentType;
use App\Domain\Receivables\PaymentMethod;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\WebFormat;
use App\Exceptions\InvalidTransitionException;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money back to a customer. A refund never invents credit: it pays out what
 * the customer already holds, from ONE source:
 *
 *  - a payment's unallocated part (they overpaid, or paid ahead and the work
 *    never happened), or
 *  - a credit note's credit (the note was for more than the invoice still
 *    owed, because the invoice was already paid).
 *
 * To refund money that has been applied to an invoice, credit the invoice
 * first; the credit note is then the source. Numbered from the `refund`
 * series when paid (R8); never edited, only voided (R7), which puts the
 * credit back.
 *
 * Locks: the source (payment or credit note), then the refund.
 */
final class ManageRefunds
{
    public function __construct(
        private readonly BillingJournal $journal,
        private readonly DocumentNumbers $numbers,
        private readonly LedgerPostings $postings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{method: string, reference_no?: string|null, amount_cents?: int|null, refunded_on?: string|null, reason: string}  $data  validated
     */
    public function pay(Payment|CreditNote $source, array $data): Refund
    {
        $method = PaymentMethod::from($data['method']);
        $reference = trim((string) ($data['reference_no'] ?? ''));
        if ($method->needsReference() && $reference === '') {
            throw ValidationException::withMessages(['reference_no' => "A {$method->label()} refund needs its reference number."]);
        }

        return DB::transaction(function () use ($source, $data, $method, $reference): Refund {
            $now = CarbonImmutable::now();
            $today = Calendar::toDate($now);
            $refundedOn = $data['refunded_on'] ?? $today;
            if ($refundedOn > $today) {
                throw ValidationException::withMessages(['refunded_on' => 'A refund is never dated in the future.']);
            }

            if ($source instanceof Payment) {
                $locked = $this->journal->lockPayment($source);
                if ($locked->status !== PaymentStatus::Posted) {
                    throw new InvalidTransitionException("Payment {$locked->number} is void; there is nothing of it to refund.");
                }
                $held = $locked->amount_cents - (int) PaymentAllocation::query()->where('payment_id', $locked->id)->sum('amount_cents');
                $refunded = (int) Refund::query()->where('payment_id', $locked->id)->where('status', PaymentStatus::Posted->value)->sum('amount_cents');
                $what = "payment {$locked->number}";
                $dated = $locked->received_on->toDateString();
            } else {
                $locked = CreditNote::query()->lockForUpdate()->findOrFail($source->id);
                if (! $locked->isStanding()) {
                    throw new InvalidTransitionException("Credit note {$locked->number} is void; there is nothing of it to refund.");
                }
                $held = $locked->credit_cents;
                $refunded = (int) Refund::query()->where('credit_note_id', $locked->id)->where('status', PaymentStatus::Posted->value)->sum('amount_cents');
                $what = "credit note {$locked->number}";
                $dated = $locked->issued_on->toDateString();
            }

            $available = $held - $refunded;
            if ($available <= 0) {
                throw new InvalidTransitionException(ucfirst($what).' holds no credit to refund.');
            }
            $amount = $data['amount_cents'] ?? $available;
            if ($amount > $available) {
                throw ValidationException::withMessages(['amount_cents' => sprintf('Only %s of %s is left to refund.', WebFormat::pesos($available), $what)]);
            }
            if ($refundedOn < $dated) {
                throw ValidationException::withMessages(['refunded_on' => "A refund is not dated before the {$what} it pays out."]);
            }

            $actor = $this->journal->actor();
            $refund = new Refund;
            $refund->forceFill([
                'branch_id' => $locked->branch_id,
                'customer_account_id' => $locked->customer_account_id,
                'number' => $this->numbers->issue($locked->organization_id, null, DocumentType::Refund, $now)->formatted,
                'status' => PaymentStatus::Posted,
                'method' => $method,
                'reference_no' => $reference === '' ? null : $reference,
                'amount_cents' => $amount,
                'refunded_on' => $refundedOn,
                'payment_id' => $locked instanceof Payment ? $locked->id : null,
                'credit_note_id' => $locked instanceof CreditNote ? $locked->id : null,
                'reason' => $data['reason'],
                'refunded_by' => $actor->id,
                'refunded_by_name' => $actor->name,
            ])->save();

            $this->postings->refundPaid($refund);
            $this->audit->record($refund, 'refunded', null, AuditTrail::snapshot($refund));

            return $refund->load(['payment', 'creditNote', 'customerAccount']);
        });
    }

    /** Void a refund: it keeps its number, and the credit it paid out is the customer's again. */
    public function void(Refund $refund, string $reason): Refund
    {
        return DB::transaction(function () use ($refund, $reason): Refund {
            if ($refund->payment_id !== null) {
                Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            } else {
                CreditNote::query()->lockForUpdate()->findOrFail((string) $refund->credit_note_id);
            }
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if (! $locked->isStanding()) {
                throw new InvalidTransitionException("Refund {$locked->number} is already void.");
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill([
                'status' => PaymentStatus::Void,
                'voided_at' => CarbonImmutable::now(),
                'voided_by_name' => $this->journal->actor()->name,
                'void_reason' => $reason,
            ])->save();
            $this->postings->refundVoided($locked, $reason);
            $this->audit->record($locked, 'voided', $before, AuditTrail::snapshot($locked));

            return $locked->load(['payment', 'creditNote', 'customerAccount']);
        });
    }
}
