<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * The lean chart a Philippine SME starts with (Phase 8). Seeded per
 * organization on first use; the organization then renames, adds to and
 * deactivates accounts, and points the posting rules where it wants them.
 */
final class ChartOfAccounts
{
    /**
     * @return list<array{code: string, name: string, type: AccountType, side: Side, description: string}>
     */
    public static function defaults(): array
    {
        $account = fn (string $code, string $name, AccountType $type, string $description = '', ?Side $side = null): array => [
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'side' => $side ?? $type->defaultSide(),
            'description' => $description,
        ];

        return [
            $account('1000', 'Cash on Hand', AccountType::Asset, 'Cash taken at the counter.'),
            $account('1010', 'Cash in Bank', AccountType::Asset, 'Bank transfers and cheques received.'),
            $account('1020', 'GCash Clearing', AccountType::Asset, 'GCash receipts until they reach the bank.'),
            $account('1021', 'Maya Clearing', AccountType::Asset, 'Maya receipts until they reach the bank.'),
            $account('1022', 'Card Clearing', AccountType::Asset, 'Card receipts until the acquirer settles.'),
            $account('1100', 'Accounts Receivable', AccountType::Asset, 'Control account: issued invoices not yet paid.'),
            $account('1200', 'Inventory', AccountType::Asset, 'Control account: the stock room at average cost.'),
            $account('1300', 'Input VAT', AccountType::Asset, 'VAT paid on purchases.'),
            $account('1310', 'Creditable Withholding Tax', AccountType::Asset, 'Tax customers withheld at source (their 2307s).'),
            $account('2000', 'Accounts Payable', AccountType::Liability, 'Control account: vendor bills not yet paid.'),
            $account('2100', 'GR/IR Clearing', AccountType::Liability, 'Goods received that no vendor bill has matched yet.'),
            $account('2200', 'Output VAT', AccountType::Liability, 'VAT charged on invoices.'),
            $account('2210', 'Withholding Tax Payable', AccountType::Liability, 'Tax withheld from vendors, owed to the BIR.'),
            $account('2300', 'Customer Deposits', AccountType::Liability, 'Money received and not yet applied to an invoice.'),
            $account('2400', 'Unearned Revenue', AccountType::Liability, 'Billed ahead of the work.'),
            $account('3000', 'Opening Balance Equity', AccountType::Equity, 'The other side of opening stock balances.'),
            $account('3100', 'Retained Earnings', AccountType::Equity, 'The profit or loss of every closed year.'),
            $account('4000', 'Sales – Labour', AccountType::Revenue, 'Labour, fees and typed-in lines.'),
            $account('4010', 'Sales – Parts', AccountType::Revenue),
            $account('4020', 'Sales – Detailing', AccountType::Revenue),
            $account('4030', 'Sales – Café', AccountType::Revenue),
            $account('4800', 'Other Income', AccountType::Revenue, 'Bank interest and other income outside sales.'),
            $account('4900', 'Sales Discounts', AccountType::Revenue, 'Discounts given, shown against sales.', Side::Debit),
            $account('4910', 'Sales Returns & Allowances', AccountType::Revenue, 'Credit notes, shown against sales.', Side::Debit),
            $account('5000', 'COGS – Parts', AccountType::Expense, 'Parts issued to jobs, at average cost.'),
            $account('5010', 'COGS – Consumables', AccountType::Expense),
            $account('5020', 'COGS – Café', AccountType::Expense),
            $account('5100', 'Inventory Adjustments', AccountType::Expense, 'Count differences and average-cost rounding.'),
            $account('5110', 'Purchase Price Variance', AccountType::Expense),
            $account('6000', 'Equipment Repairs & Maintenance', AccountType::Expense),
            $account('6010', 'Bank Charges', AccountType::Expense),
            $account('6100', 'Rent', AccountType::Expense),
            $account('6110', 'Utilities', AccountType::Expense),
            $account('6120', 'Office & Shop Supplies', AccountType::Expense),
            $account('6130', 'Professional Fees', AccountType::Expense),
            $account('6190', 'Miscellaneous Expenses', AccountType::Expense),
        ];
    }
}
