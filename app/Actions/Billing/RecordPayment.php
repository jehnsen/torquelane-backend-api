<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Ledger\LedgerPostings;
use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Numbering\DocumentType;
use App\Domain\Receivables\Allocation;
use App\Domain\Receivables\AllocationRefused;
use App\Domain\Receivables\OpenInvoice;
use App\Domain\Receivables\PaymentMethod;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Events\PaymentReceived;
use App\Exceptions\InvalidTransitionException;
use App\Models\CustomerAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money in. A payment is numbered from the `payment` series when recorded
 * (R8) and is then an issued document (R7): never edited, only voided.
 *
 * It is spread over the account's open invoices, as the request says, or
 * oldest due first when it says nothing; what is left over is the customer's
 * credit, applied later (`allocate`). Every invoice it touches has its paid
 * figure and status recomputed in the same transaction (InvoiceSettlement),
 * so a paid invoice's jobs are stamped collected there and then.
 *
 * Locks: the account's open invoices first (id order), then the payment, in
 * every action here, so two of them never deadlock.
 */
final class RecordPayment
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly BillingJournal $journal,
        private readonly InvoiceSettlement $settlement,
        private readonly DocumentNumbers $numbers,
        private readonly LedgerPostings $postings,
    ) {}

    /**
     * @param  array{branch_id: string, method: string, reference_no?: string|null, amount_cents: int, received_on?: string|null, notes?: string|null, allocations?: list<array{invoice_id: string, amount_cents: int}>|null}  $data  validated
     */
    public function record(CustomerAccount $account, array $data): Payment
    {
        $method = PaymentMethod::from($data['method']);
        $reference = trim((string) ($data['reference_no'] ?? ''));
        if ($method->needsReference() && $reference === '') {
            throw ValidationException::withMessages(['reference_no' => "A {$method->label()} payment needs its reference number."]);
        }

        return DB::transaction(function () use ($account, $data, $method, $reference): Payment {
            $now = CarbonImmutable::now();
            $receivedOn = $this->receivedOn($data['received_on'] ?? null, $now);
            $open = $this->lockOpenInvoices($account->id);
            $actor = $this->journal->actor();

            $payment = new Payment;
            $payment->forceFill([
                'branch_id' => $data['branch_id'],
                'customer_account_id' => $account->id,
                'number' => $this->numbers->issue($account->organization_id, null, DocumentType::Payment, $now)->formatted,
                'status' => PaymentStatus::Posted,
                'method' => $method,
                'reference_no' => $reference === '' ? null : $reference,
                'amount_cents' => $data['amount_cents'],
                'received_on' => $receivedOn,
                'received_by' => $actor->id,
                'received_by_name' => $actor->name,
                'notes' => trim((string) ($data['notes'] ?? '')),
            ])->save();

            // The money is booked as the customer's deposit first; each allocation then applies it to an invoice.
            $this->postings->paymentReceived($payment);
            $this->apply($payment, $open, $data['allocations'] ?? null, $receivedOn, $now);
            $this->journal->auditPayment($payment, 'received', null);
            event(new PaymentReceived($payment->organization_id, $payment->id, $payment->number));

            return $payment->load('allocations');
        });
    }

    /**
     * Apply what is left of a payment (the customer's credit) to open invoices.
     *
     * @param  list<array{invoice_id: string, amount_cents: int}>|null  $allocations  null = oldest due first
     */
    public function allocate(Payment $payment, ?array $allocations): Payment
    {
        return DB::transaction(function () use ($payment, $allocations): Payment {
            $open = $this->lockOpenInvoices($payment->customer_account_id);
            $locked = $this->journal->lockPayment($payment)->load('allocations');
            if ($locked->status !== PaymentStatus::Posted) {
                throw new InvalidTransitionException("Payment {$locked->number} is void; it has nothing to allocate.");
            }
            if ($locked->unallocatedCents() === 0) {
                throw new InvalidTransitionException("Payment {$locked->number} is fully allocated.");
            }
            $before = BillingJournal::paymentSnapshot($locked);
            $now = CarbonImmutable::now();

            if ($this->apply($locked, $open, $allocations, Calendar::toDate($now), $now) === 0) {
                throw ValidationException::withMessages(['allocations' => 'This account has no open invoice to apply the credit to.']);
            }
            $this->journal->auditPayment($locked, 'allocated', $before);

            return $locked->load('allocations');
        });
    }

    /** Void a payment: it keeps its number; every invoice it paid is owed again. */
    public function void(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            $invoiceIds = array_values(PaymentAllocation::query()->where('payment_id', $payment->id)->pluck('invoice_id')->map(fn (mixed $id): string => is_string($id) ? $id : '')->unique()->all());
            $invoices = $this->journal->lockInvoices($invoiceIds);
            $locked = $this->journal->lockPayment($payment);
            if ($locked->status !== PaymentStatus::Posted) {
                throw new InvalidTransitionException("Payment {$locked->number} is already void.");
            }
            $before = BillingJournal::paymentSnapshot($locked);

            $locked->forceFill([
                'status' => PaymentStatus::Void,
                'voided_at' => CarbonImmutable::now(),
                'voided_by_name' => $this->journal->actor()->name,
                'void_reason' => $reason,
            ])->save();
            foreach ($invoices as $invoice) {
                $this->settlement->refresh($invoice);
            }
            $this->postings->paymentVoided($locked, $reason);
            $this->journal->auditPayment($locked, 'voided', $before);

            return $locked->load('allocations');
        });
    }

    /**
     * Writes the allocations and settles each invoice; returns the centavos applied.
     *
     * @param  array<string, Invoice>  $open  locked, by id
     * @param  list<array{invoice_id: string, amount_cents: int}>|null  $requested
     */
    private function apply(Payment $payment, array $open, ?array $requested, string $allocatedOn, CarbonImmutable $now): int
    {
        $available = $payment->amount_cents - (int) PaymentAllocation::query()->where('payment_id', $payment->id)->sum('amount_cents');
        $candidates = [];
        foreach ($open as $invoice) {
            if (! $invoice->status->isOpen()) {
                continue;
            }
            $candidates[$invoice->id] = new OpenInvoice($invoice->id, (string) $invoice->number, $invoice->customer_account_id, (string) $invoice->issue_date?->toDateString(), (string) $invoice->due_date?->toDateString(), $invoice->total_due_cents, $invoice->paid_cents);
        }

        try {
            $plan = $requested === null
                ? Allocation::oldestFirst($available, array_values($candidates))
                : Allocation::checked($requested, $available, $candidates);
        } catch (AllocationRefused $e) {
            throw ValidationException::withMessages([$e->index === null ? 'allocations' : "allocations.{$e->index}" => $e->getMessage()]);
        }

        $actor = $this->journal->actor();
        foreach ($plan as $invoiceId => $amount) {
            $allocation = new PaymentAllocation;
            $allocation->forceFill([
                'payment_id' => $payment->id,
                'invoice_id' => $invoiceId,
                'customer_account_id' => $payment->customer_account_id,
                'amount_cents' => $amount,
                'allocated_on' => $allocatedOn,
                'allocated_at' => $now,
                'allocated_by' => $actor->id,
                'allocated_by_name' => $actor->name,
            ])->save();
            $this->postings->creditApplied($allocation, $payment, $open[$invoiceId]);
            $this->settlement->refresh($open[$invoiceId], $now);
        }

        return array_sum($plan);
    }

    /**
     * The account's open invoices the caller may see, locked in id order.
     *
     * @return array<string, Invoice>
     */
    private function lockOpenInvoices(string $accountId): array
    {
        $ids = array_values(Invoice::query()
            ->visibleTo($this->tenancy->require())
            ->where('customer_account_id', $accountId)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->pluck('id')
            ->map(fn (mixed $id): string => is_string($id) ? $id : '')
            ->all());

        return $this->journal->lockInvoices($ids);
    }

    private function receivedOn(?string $requested, CarbonImmutable $now): string
    {
        $today = Calendar::toDate($now);
        if ($requested !== null && $requested > $today) {
            throw ValidationException::withMessages(['received_on' => 'A payment is never dated in the future.']);
        }

        return $requested ?? $today;
    }
}
