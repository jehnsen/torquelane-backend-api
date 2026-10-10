<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * The period-close checklist: the books may be closed for a month only when
 * every check passes.
 *
 *  1. no source document is unposted (every issued invoice, void, payment,
 *     application and stock move has its entry);
 *  2. the receivables subledger (open invoices) equals the Accounts Receivable account;
 *  3. the stock room's valuation equals the Inventory account;
 *  4. customer credit not yet applied equals the Customer Deposits account;
 *  5. the trial balance balances.
 */
final class CloseChecklist
{
    /**
     * @param  array<string, int>  $unposted  count of unposted sources by kind
     * @return list<array{key: string, label: string, passed: bool, expected_cents: int|null, actual_cents: int|null, difference_cents: int|null, detail: string}>
     */
    public static function evaluate(
        array $unposted,
        int $receivablesSubledger,
        int $receivablesAccount,
        int $inventoryValuation,
        int $inventoryAccount,
        int $unappliedCredit,
        int $depositsAccount,
        int $trialDebits,
        int $trialCredits,
    ): array {
        $count = array_sum($unposted);
        $kinds = array_filter($unposted, fn (int $n): bool => $n > 0);

        return [
            [
                'key' => 'unposted_sources',
                'label' => 'Every invoice, payment and stock move is posted',
                'passed' => $count === 0,
                'expected_cents' => null,
                'actual_cents' => null,
                'difference_cents' => null,
                'detail' => $count === 0 ? 'Nothing is waiting to be posted.' : sprintf('%d waiting: %s.', $count, implode(', ', array_map(fn (string $kind, int $n): string => "{$n} {$kind}", array_keys($kinds), $kinds))),
            ],
            self::compare('receivables', 'Receivables subledger equals Accounts Receivable', $receivablesSubledger, $receivablesAccount, 'Open invoices', 'Accounts Receivable'),
            self::compare('inventory', 'Stock valuation equals Inventory', $inventoryValuation, $inventoryAccount, 'Stock room valuation', 'Inventory account'),
            self::compare('customer_deposits', 'Unapplied customer credit equals Customer Deposits', $unappliedCredit, $depositsAccount, 'Unapplied credit', 'Customer Deposits account'),
            self::compare('trial_balance', 'The trial balance balances', $trialDebits, $trialCredits, 'Debits', 'Credits'),
        ];
    }

    /**
     * @param  list<array{passed: bool}>  $checks
     */
    public static function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! $check['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{key: string, label: string, passed: bool, expected_cents: int|null, actual_cents: int|null, difference_cents: int|null, detail: string}
     */
    private static function compare(string $key, string $label, int $expected, int $actual, string $expectedName, string $actualName): array
    {
        $difference = $actual - $expected;

        return [
            'key' => $key,
            'label' => $label,
            'passed' => $difference === 0,
            'expected_cents' => $expected,
            'actual_cents' => $actual,
            'difference_cents' => $difference,
            'detail' => $difference === 0 ? "{$expectedName} and {$actualName} agree." : "{$actualName} is {$difference} centavos off {$expectedName}.",
        ];
    }
}
