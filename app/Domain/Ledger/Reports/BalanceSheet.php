<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Reports;

use App\Domain\Ledger\AccountBalance;
use App\Domain\Ledger\AccountType;

/**
 * A simple balance sheet: assets; liabilities; equity, including the
 * earnings made to date (revenue less expenses since the books began, there
 * being no year-end close yet). Assets equal liabilities plus equity when
 * the books balance.
 */
final class BalanceSheet
{
    /**
     * @param  list<AccountBalance>  $balances  one per account, cumulative to the date (branches already summed)
     * @return array{assets: list<array{account_id: string, code: string, name: string, amount_cents: int}>, liabilities: list<array{account_id: string, code: string, name: string, amount_cents: int}>, equity: list<array{account_id: string, code: string, name: string, amount_cents: int}>, current_earnings_cents: int, total_assets_cents: int, total_liabilities_cents: int, total_equity_cents: int, balanced: bool}
     */
    public static function build(array $balances): array
    {
        usort($balances, fn (AccountBalance $a, AccountBalance $b): int => strcmp($a->code, $b->code));

        $assets = [];
        $liabilities = [];
        $equity = [];
        $assetTotal = 0;
        $liabilityTotal = 0;
        $equityTotal = 0;
        $earnings = 0;
        foreach ($balances as $balance) {
            if ($balance->type === AccountType::Revenue || $balance->type === AccountType::Expense) {
                // Revenue is credit-positive and an expense debit-positive: either way, earnings = credits less debits.
                $earnings -= $balance->netDebitCents();

                continue;
            }
            if ($balance->debitCents === 0 && $balance->creditCents === 0) {
                continue;
            }
            // Assets are shown debit-positive; liabilities and equity credit-positive.
            $amount = $balance->type === AccountType::Asset ? $balance->netDebitCents() : -$balance->netDebitCents();
            $row = ['account_id' => $balance->accountId, 'code' => $balance->code, 'name' => $balance->name, 'amount_cents' => $amount];
            if ($balance->type === AccountType::Asset) {
                $assets[] = $row;
                $assetTotal += $amount;
            } elseif ($balance->type === AccountType::Liability) {
                $liabilities[] = $row;
                $liabilityTotal += $amount;
            } else {
                $equity[] = $row;
                $equityTotal += $amount;
            }
        }
        $equityTotal += $earnings;

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_earnings_cents' => $earnings,
            'total_assets_cents' => $assetTotal,
            'total_liabilities_cents' => $liabilityTotal,
            'total_equity_cents' => $equityTotal,
            'balanced' => $assetTotal === $liabilityTotal + $equityTotal,
        ];
    }
}
