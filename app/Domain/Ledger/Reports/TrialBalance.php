<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Reports;

use App\Domain\Ledger\AccountBalance;

/** A trial balance: every account with activity, its net balance in the debit or the credit column, and whether the two columns agree. */
final class TrialBalance
{
    /**
     * @param  list<AccountBalance>  $balances  one per account (branches already summed)
     * @return array{accounts: list<array<string, mixed>>, total_debit_cents: int, total_credit_cents: int, difference_cents: int, balanced: bool}
     */
    public static function build(array $balances): array
    {
        usort($balances, fn (AccountBalance $a, AccountBalance $b): int => strcmp($a->code, $b->code));

        $rows = [];
        $debits = 0;
        $credits = 0;
        foreach ($balances as $balance) {
            if ($balance->debitCents === 0 && $balance->creditCents === 0) {
                continue;
            }
            $net = $balance->netDebitCents();
            $debit = max($net, 0);
            $credit = max(-$net, 0);
            $debits += $debit;
            $credits += $credit;
            $rows[] = [
                'account_id' => $balance->accountId,
                'code' => $balance->code,
                'name' => $balance->name,
                'type' => $balance->type->value,
                'debit_cents' => $debit,
                'credit_cents' => $credit,
            ];
        }

        return [
            'accounts' => $rows,
            'total_debit_cents' => $debits,
            'total_credit_cents' => $credits,
            'difference_cents' => $debits - $credits,
            'balanced' => $debits === $credits,
        ];
    }
}
