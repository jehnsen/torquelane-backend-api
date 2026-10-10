<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Reports;

use App\Domain\Ledger\AccountBalance;
use App\Domain\Ledger\AccountType;

/**
 * Profit and loss over a range, with a column per branch and a consolidated
 * total: revenue (net of discounts), cost of sales, gross profit, other
 * expenses, net profit. The cost-of-sales accounts are the ones the posting
 * rules point `cogs.*` at.
 *
 * @phpstan-type Line array{account_id: string, code: string, name: string, by_branch: array<string, int>, total_cents: int}
 * @phpstan-type Subtotal array{by_branch: array<string, int>, total_cents: int}
 */
final class ProfitAndLoss
{
    /**
     * @param  list<AccountBalance>  $balances  one per (account, branch); only revenue and expense accounts matter
     * @param  list<string>  $branchIds  the columns, in order
     * @param  list<string>  $costOfSalesAccountIds
     * @return array{branch_ids: list<string>, revenue: list<Line>, cost_of_sales: list<Line>, gross_profit: Subtotal, expenses: list<Line>, net_profit: Subtotal}
     */
    public static function build(array $balances, array $branchIds, array $costOfSalesAccountIds): array
    {
        /** @var array<string, Line> $lines */
        $lines = [];
        /** @var array<string, string> $sectionOf */
        $sectionOf = [];
        foreach ($balances as $balance) {
            if ($balance->type !== AccountType::Revenue && $balance->type !== AccountType::Expense) {
                continue;
            }
            $id = $balance->accountId;
            $sectionOf[$id] = $balance->type === AccountType::Revenue ? 'revenue' : (in_array($id, $costOfSalesAccountIds, true) ? 'cost_of_sales' : 'expenses');
            $lines[$id] ??= ['account_id' => $id, 'code' => $balance->code, 'name' => $balance->name, 'by_branch' => array_fill_keys($branchIds, 0), 'total_cents' => 0];
            // Revenue grows on the credit side, an expense on the debit side.
            $amount = $balance->type === AccountType::Revenue ? -$balance->netDebitCents() : $balance->netDebitCents();
            $branch = $balance->branchId ?? '';
            if (array_key_exists($branch, $lines[$id]['by_branch'])) {
                $lines[$id]['by_branch'][$branch] += $amount;
            }
            $lines[$id]['total_cents'] += $amount;
        }

        $sections = ['revenue' => [], 'cost_of_sales' => [], 'expenses' => []];
        foreach ($lines as $id => $line) {
            if ($line['total_cents'] !== 0 || array_sum($line['by_branch']) !== 0) {
                $sections[$sectionOf[$id]][] = $line;
            }
        }
        foreach ($sections as $name => $rows) {
            usort($rows, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
            $sections[$name] = $rows;
        }

        $revenue = self::sum($sections['revenue'], $branchIds);
        $cost = self::sum($sections['cost_of_sales'], $branchIds);
        $expenses = self::sum($sections['expenses'], $branchIds);
        $gross = self::minus($revenue, $cost, $branchIds);

        return [
            'branch_ids' => $branchIds,
            'revenue' => $sections['revenue'],
            'cost_of_sales' => $sections['cost_of_sales'],
            'gross_profit' => $gross,
            'expenses' => $sections['expenses'],
            'net_profit' => self::minus($gross, $expenses, $branchIds),
        ];
    }

    /**
     * @param  list<Line>  $rows
     * @param  list<string>  $branchIds
     * @return Subtotal
     */
    private static function sum(array $rows, array $branchIds): array
    {
        $by = array_fill_keys($branchIds, 0);
        $total = 0;
        foreach ($rows as $row) {
            foreach ($row['by_branch'] as $branch => $cents) {
                $by[$branch] += $cents;
            }
            $total += $row['total_cents'];
        }

        return ['by_branch' => $by, 'total_cents' => $total];
    }

    /**
     * @param  Subtotal  $a
     * @param  Subtotal  $b
     * @param  list<string>  $branchIds
     * @return Subtotal
     */
    private static function minus(array $a, array $b, array $branchIds): array
    {
        $by = [];
        foreach ($branchIds as $branch) {
            $by[$branch] = $a['by_branch'][$branch] - $b['by_branch'][$branch];
        }

        return ['by_branch' => $by, 'total_cents' => $a['total_cents'] - $b['total_cents']];
    }
}
