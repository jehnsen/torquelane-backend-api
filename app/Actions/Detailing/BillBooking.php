<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Billing\ManageInvoices;
use App\Actions\Billing\RecordPayment;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Invoicing\InvoiceStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A finished booking becomes an invoice (Phase 7): the package at the price
 * it was booked at, and each extra as its own line. Only a job that is
 * completed AND signed off is billed; it is billed once.
 *
 * The counter's checkout is the same thing done in one go: raise the invoice
 * (or pick up the one already raised), issue it, and take the payment against
 * it, all in one transaction, so the customer never leaves with an invoice
 * issued and no payment recorded, or the other way round.
 */
final class BillBooking
{
    public function __construct(
        private readonly DetailingJournal $journal,
        private readonly ManageInvoices $invoices,
        private readonly RecordPayment $payments,
    ) {}

    /** A draft invoice for the booking. */
    public function invoice(DetailingBooking $booking, string $notes = ''): Invoice
    {
        return DB::transaction(function () use ($booking, $notes): Invoice {
            $locked = $this->journal->lockBooking($booking);
            $this->assertBillable($locked);
            if ($locked->load('invoiceLinks')->standingInvoiceLink() !== null) {
                throw new ConflictException("{$locked->reference} is already on an invoice.");
            }

            $invoice = $this->invoices->fromBooking($locked->load('addons'), $notes);
            $this->journal->announce($locked, 'invoiced');

            return $invoice;
        });
    }

    /**
     * Invoice and payment in one action.
     *
     * @param  array{method: string, reference_no?: string|null, amount_cents?: int|null, notes?: string|null}  $payment  `amount_cents` defaults to everything due
     * @return array{invoice: Invoice, payment: Payment|null}
     */
    public function checkout(DetailingBooking $booking, array $payment): array
    {
        return DB::transaction(function () use ($booking, $payment): array {
            $locked = $this->journal->lockBooking($booking);
            $this->assertBillable($locked);

            $link = $locked->load('invoiceLinks')->standingInvoiceLink();
            $invoice = $link === null
                ? $this->invoices->fromBooking($locked->load('addons'))
                : Invoice::query()->lockForUpdate()->findOrFail($link->invoice_id);
            if ($invoice->status === InvoiceStatus::Draft) {
                $invoice = $this->invoices->issue($invoice);
            }
            if ($invoice->status === InvoiceStatus::Paid) {
                throw new ConflictException("{$locked->reference} is already paid (invoice {$invoice->number}).");
            }

            $due = $invoice->balanceCents();
            $amount = $payment['amount_cents'] ?? $due;
            if ($amount > $due) {
                throw ValidationException::withMessages(['amount_cents' => 'The counter takes no more than what the invoice is due; record an advance payment under Billing.']);
            }

            $received = null;
            if ($due > 0) {
                if ($amount < 1) {
                    throw ValidationException::withMessages(['amount_cents' => 'A payment is at least one centavo.']);
                }
                $received = $this->payments->record(CustomerAccount::query()->findOrFail($locked->customer_account_id), [
                    'branch_id' => $locked->branch_id,
                    'method' => $payment['method'],
                    'reference_no' => $payment['reference_no'] ?? null,
                    'amount_cents' => $amount,
                    'notes' => $payment['notes'] ?? "Counter checkout, {$locked->reference}",
                    'allocations' => [['invoice_id' => $invoice->id, 'amount_cents' => $amount]],
                ]);
            }
            $this->journal->announce($locked, 'checked_out');

            return ['invoice' => $invoice->refresh(), 'payment' => $received];
        });
    }

    private function assertBillable(DetailingBooking $booking): void
    {
        if ($booking->status !== BookingStatus::Completed) {
            throw new InvalidTransitionException("{$booking->reference} is {$booking->status->label()}; only a completed job is billed.");
        }
        if ($booking->qc_status !== DetailingBooking::QC_PASSED) {
            throw new InvalidTransitionException("{$booking->reference} has not been signed off; quality control comes before the bill.");
        }
    }
}
