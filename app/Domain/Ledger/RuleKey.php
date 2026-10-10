<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * What a posting names instead of an account: a category or a role in an
 * event ("where does cash by GCash go", "what does a parts sale credit").
 * `posting_rules` maps each key to one of the organization's accounts, so the
 * chart can be reshaped without touching a posting. Every key has a default
 * account in the seeded chart.
 */
enum RuleKey: string
{
    case CashOnHand = 'cash.on_hand';
    case CashBank = 'cash.bank';
    case ClearingGcash = 'clearing.gcash';
    case ClearingMaya = 'clearing.maya';
    case ClearingCard = 'clearing.card';
    case Receivables = 'receivables.control';
    case Inventory = 'inventory.stock';
    case InputVat = 'tax.input_vat';
    case Payables = 'payables.control';
    case GoodsReceivedClearing = 'clearing.grir';
    case OutputVat = 'tax.output_vat';
    case CustomerDeposits = 'liability.customer_deposits';
    case UnearnedRevenue = 'liability.unearned_revenue';
    case OpeningBalanceEquity = 'equity.opening_balance';
    case SalesLabour = 'sales.labour';
    case SalesParts = 'sales.parts';
    case SalesDetailing = 'sales.detailing';
    case SalesCafe = 'sales.cafe';
    /** Flat fees on a job (the misc fee). */
    case SalesFees = 'sales.fees';
    /** Typed-in invoice lines. */
    case SalesManual = 'sales.manual';
    case SalesDiscounts = 'sales.discounts';
    case CogsParts = 'cogs.parts';
    case CogsConsumables = 'cogs.consumables';
    case CogsCafe = 'cogs.cafe';
    case InventoryAdjustments = 'inventory.adjustments';
    case PurchasePriceVariance = 'inventory.price_variance';
    case EquipmentRepairs = 'expense.equipment_repairs';

    public function label(): string
    {
        return match ($this) {
            self::CashOnHand => 'Cash received in cash',
            self::CashBank => 'Cash received by bank transfer or cheque',
            self::ClearingGcash => 'Received by GCash',
            self::ClearingMaya => 'Received by Maya',
            self::ClearingCard => 'Received by card',
            self::Receivables => 'Amounts customers owe (control)',
            self::Inventory => 'Stock on the shelf',
            self::InputVat => 'VAT paid on purchases',
            self::Payables => 'Amounts owed to vendors (control)',
            self::GoodsReceivedClearing => 'Goods received, not yet billed',
            self::OutputVat => 'VAT charged on sales',
            self::CustomerDeposits => 'Customer payments not yet applied',
            self::UnearnedRevenue => 'Billed ahead of the work',
            self::OpeningBalanceEquity => 'Opening stock balances',
            self::SalesLabour => 'Labour sales',
            self::SalesParts => 'Parts sales',
            self::SalesDetailing => 'Detailing sales',
            self::SalesCafe => 'Café sales',
            self::SalesFees => 'Fees on a job',
            self::SalesManual => 'Typed-in invoice lines',
            self::SalesDiscounts => 'Discounts given',
            self::CogsParts => 'Cost of parts used',
            self::CogsConsumables => 'Cost of consumables used',
            self::CogsCafe => 'Cost of café goods',
            self::InventoryAdjustments => 'Stock count differences and costing rounding',
            self::PurchasePriceVariance => 'Purchase price differences',
            self::EquipmentRepairs => 'Equipment repairs and maintenance',
        };
    }

    /** The code of the account the seeded chart gives this key. */
    public function defaultCode(): string
    {
        return match ($this) {
            self::CashOnHand => '1000',
            self::CashBank => '1010',
            self::ClearingGcash => '1020',
            self::ClearingMaya => '1021',
            self::ClearingCard => '1022',
            self::Receivables => '1100',
            self::Inventory => '1200',
            self::InputVat => '1300',
            self::Payables => '2000',
            self::GoodsReceivedClearing => '2100',
            self::OutputVat => '2200',
            self::CustomerDeposits => '2300',
            self::UnearnedRevenue => '2400',
            self::OpeningBalanceEquity => '3000',
            self::SalesLabour, self::SalesFees, self::SalesManual => '4000',
            self::SalesParts => '4010',
            self::SalesDetailing => '4020',
            self::SalesCafe => '4030',
            self::SalesDiscounts => '4900',
            self::CogsParts => '5000',
            self::CogsConsumables => '5010',
            self::CogsCafe => '5020',
            self::InventoryAdjustments => '5100',
            self::PurchasePriceVariance => '5110',
            self::EquipmentRepairs => '6000',
        };
    }

    /** The account type this key may be pointed at. */
    public function accountType(): AccountType
    {
        return match ($this) {
            self::CashOnHand, self::CashBank, self::ClearingGcash, self::ClearingMaya, self::ClearingCard,
            self::Receivables, self::Inventory, self::InputVat => AccountType::Asset,
            self::Payables, self::GoodsReceivedClearing, self::OutputVat, self::CustomerDeposits, self::UnearnedRevenue => AccountType::Liability,
            self::OpeningBalanceEquity => AccountType::Equity,
            self::SalesLabour, self::SalesParts, self::SalesDetailing, self::SalesCafe, self::SalesFees, self::SalesManual, self::SalesDiscounts => AccountType::Revenue,
            self::CogsParts, self::CogsConsumables, self::CogsCafe, self::InventoryAdjustments, self::PurchasePriceVariance, self::EquipmentRepairs => AccountType::Expense,
        };
    }

    /** Whether the key is a cost of goods sold (the profit and loss splits gross profit on these). */
    public function isCostOfSales(): bool
    {
        return match ($this) {
            self::CogsParts, self::CogsConsumables, self::CogsCafe => true,
            default => false,
        };
    }

    /** The group a rules screen lists it under. */
    public function group(): string
    {
        return match ($this) {
            self::CashOnHand, self::CashBank, self::ClearingGcash, self::ClearingMaya, self::ClearingCard => 'Money received',
            self::Receivables, self::CustomerDeposits, self::UnearnedRevenue, self::OutputVat => 'Sales and receivables',
            self::SalesLabour, self::SalesParts, self::SalesDetailing, self::SalesCafe, self::SalesFees, self::SalesManual, self::SalesDiscounts => 'Sales',
            self::Inventory, self::GoodsReceivedClearing, self::OpeningBalanceEquity, self::InventoryAdjustments, self::PurchasePriceVariance => 'Stock',
            self::CogsParts, self::CogsConsumables, self::CogsCafe => 'Cost of sales',
            self::InputVat, self::Payables, self::EquipmentRepairs => 'Purchasing and expenses',
        };
    }
}
