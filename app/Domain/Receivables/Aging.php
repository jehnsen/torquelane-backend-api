<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

use App\Domain\Shared\Calendar;

/**
 * Accounts-receivable aging as of a business date: each open invoice's
 * balance (as of that date) in the bucket of its days past due, per account,
 * with totals. An invoice not yet due is `current`.
 */
final class Aging
{
    public static function daysPastDue(string $dueDate, string $asOf): int
    {
        return Calendar::differenceInCalendarDays(Calendar::parseDate($asOf), Calendar::parseDate($dueDate));
    }

    /**
     * @param  list<OpenInvoice>  $invoices  balances as of $asOf (zero balances are skipped)
     * @return array{accounts: array<string, array<string, int>>, totals: array<string, int>} bucket → centavos, plus `total`; accounts in first-seen order
     */
    public static function report(array $invoices, string $asOf): array
    {
        $accounts = [];
        $totals = self::empty();
        foreach ($invoices as $invoice) {
            $balance = $invoice->balanceCents();
            if ($balance === 0) {
                continue;
            }
            $bucket = AgingBucket::forDaysPastDue(self::daysPastDue($invoice->dueDate, $asOf))->value;
            $accounts[$invoice->customerAccountId] ??= self::empty();
            $accounts[$invoice->customerAccountId][$bucket] += $balance;
            $accounts[$invoice->customerAccountId]['total'] += $balance;
            $totals[$bucket] += $balance;
            $totals['total'] += $balance;
        }

        return ['accounts' => $accounts, 'totals' => $totals];
    }

    /**
     * @return array<string, int>
     */
    public static function empty(): array
    {
        $row = [];
        foreach (AgingBucket::cases() as $bucket) {
            $row[$bucket->value] = 0;
        }

        return $row + ['total' => 0];
    }
}
