<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Receivables\PaymentStatus;
use App\Models\Invoice;
use App\Models\InvoiceWorkOrder;
use App\Models\PaymentAllocation;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;

/**
 * The ONLY writer of an invoice's `paid_cents` and its paid status, and of
 * its work orders' `collected_at`. Called, in the caller's transaction and
 * with the invoice locked, whenever money moves on it: an allocation, a
 * payment void, the issue itself (nothing due is paid at once).
 *
 * paid = the allocations of its payments that still stand (re-summed, never
 * incremented); the status follows (`InvoiceStatus::settled`). The database
 * checks the same sum at commit.
 *
 * `collected_at` is the Phase 3 field the shop reports read as "revenue
 * recognised": kept as a derived compatibility field, it is stamped on every
 * work order the invoice carries when the invoice becomes PAID, and cleared
 * again if a payment void takes it back below paid.
 */
final class InvoiceSettlement
{
    public function __construct(private readonly BillingJournal $journal) {}

    public function refresh(Invoice $invoice, ?CarbonImmutable $at = null): Invoice
    {
        if (! $invoice->status->isStanding()) {
            return $invoice;
        }

        $paid = (int) PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.invoice_id', $invoice->id)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->sum('payment_allocations.amount_cents');
        $status = InvoiceStatus::settled($paid, $invoice->total_due_cents);
        $wasPaid = $invoice->status === InvoiceStatus::Paid;

        if ($paid !== $invoice->paid_cents || $status !== $invoice->status) {
            $invoice->forceFill(['paid_cents' => $paid, 'status' => $status])->save();
        }

        if ($status === InvoiceStatus::Paid && ! $wasPaid) {
            $this->stampCollected($invoice, $at ?? CarbonImmutable::now());
        } elseif ($status !== InvoiceStatus::Paid && $wasPaid) {
            $this->clearCollected($invoice);
        }

        return $invoice;
    }

    private function stampCollected(Invoice $invoice, CarbonImmutable $at): void
    {
        WorkOrder::query()
            ->whereIn('id', $this->standingOrderIds($invoice))
            ->whereNull('collected_at')
            ->update(['collected_at' => $at, 'collected_by' => $this->journal->actor()->id, 'updated_at' => CarbonImmutable::now()]);
    }

    private function clearCollected(Invoice $invoice): void
    {
        WorkOrder::query()
            ->whereIn('id', $this->standingOrderIds($invoice))
            ->update(['collected_at' => null, 'collected_by' => null, 'updated_at' => CarbonImmutable::now()]);
    }

    /**
     * @return list<string>
     */
    private function standingOrderIds(Invoice $invoice): array
    {
        return array_values(InvoiceWorkOrder::query()
            ->where('invoice_id', $invoice->id)
            ->whereNull('released_at')
            ->pluck('work_order_id')
            ->map(fn (mixed $id): string => is_string($id) ? $id : '')
            ->all());
    }
}
