<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

use App\Domain\Shared\WebFormat;

/**
 * How a payment is spread over an account's open invoices. What it does not
 * cover stays with the customer as credit.
 */
final class Allocation
{
    /**
     * Oldest due first (then oldest issued, then number): each invoice takes
     * what is left of the payment, up to its balance.
     *
     * @param  list<OpenInvoice>  $open
     * @return array<string, int> invoice id → centavos, in the order applied
     */
    public static function oldestFirst(int $amountCents, array $open): array
    {
        usort($open, fn (OpenInvoice $a, OpenInvoice $b): int => [$a->dueDate, $a->issueDate, $a->number] <=> [$b->dueDate, $b->issueDate, $b->number]);

        $plan = [];
        $left = $amountCents;
        foreach ($open as $invoice) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $invoice->balanceCents());
            if ($take > 0) {
                $plan[$invoice->id] = $take;
                $left -= $take;
            }
        }

        return $plan;
    }

    /**
     * Refuses a requested spread that does not fit: an invoice named twice,
     * one that is not open, more than its balance, or more than the payment
     * has left.
     *
     * @param  array<int, array{invoice_id: string, amount_cents: int}>  $requested
     * @param  array<string, OpenInvoice>  $open  by id
     * @return array<string, int> invoice id → centavos
     *
     * @throws AllocationRefused
     */
    public static function checked(array $requested, int $availableCents, array $open): array
    {
        $plan = [];
        $total = 0;
        foreach ($requested as $index => $row) {
            $id = $row['invoice_id'];
            if (isset($plan[$id])) {
                throw new AllocationRefused('Each invoice is allocated once per payment.', $index);
            }
            $invoice = $open[$id] ?? throw new AllocationRefused('That invoice is not open for this account.', $index);
            if ($row['amount_cents'] <= 0) {
                throw new AllocationRefused('Allocate more than nothing.', $index);
            }
            if ($row['amount_cents'] > $invoice->balanceCents()) {
                throw new AllocationRefused(sprintf('Invoice %s has only %s outstanding.', $invoice->number, WebFormat::pesos($invoice->balanceCents())), $index);
            }
            $plan[$id] = $row['amount_cents'];
            $total += $row['amount_cents'];
        }
        if ($total > $availableCents) {
            throw new AllocationRefused(sprintf('The allocations add up to %s; only %s is available.', WebFormat::pesos($total), WebFormat::pesos($availableCents)));
        }

        return $plan;
    }
}
