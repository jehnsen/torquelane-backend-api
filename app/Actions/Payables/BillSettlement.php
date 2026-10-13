<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Payables\BillStatus;
use App\Domain\Receivables\PaymentStatus;
use App\Models\VendorBill;
use App\Models\VendorPaymentAllocation;

/**
 * The ONLY writer of a vendor bill's `paid_cents` and its paid status.
 * Called, in the caller's transaction and with the bill locked, whenever
 * money moves on it: a payment, or a payment's void.
 *
 * paid = the allocations of its payments that still stand (re-summed, never
 * incremented); the status follows (`BillStatus::settled`). The database
 * checks the same sum at commit.
 */
final class BillSettlement
{
    public function refresh(VendorBill $bill): VendorBill
    {
        if (! $bill->status->isStanding()) {
            return $bill;
        }

        $paid = (int) VendorPaymentAllocation::query()
            ->join('vendor_payments', 'vendor_payments.id', '=', 'vendor_payment_allocations.vendor_payment_id')
            ->where('vendor_payment_allocations.vendor_bill_id', $bill->id)
            ->where('vendor_payments.status', PaymentStatus::Posted->value)
            ->sum('vendor_payment_allocations.amount_cents');
        $status = BillStatus::settled($paid, $bill->payable_cents);

        if ($paid !== $bill->paid_cents || $status !== $bill->status) {
            $bill->forceFill(['paid_cents' => $paid, 'status' => $status])->save();
        }

        return $bill;
    }
}
