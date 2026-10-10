<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/** What an account is. Assets and expenses grow on the debit side, the rest on the credit side. */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function defaultSide(): Side
    {
        return match ($this) {
            self::Asset, self::Expense => Side::Debit,
            self::Liability, self::Equity, self::Revenue => Side::Credit,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** On the balance sheet (as opposed to the profit and loss). */
    public function isBalanceSheet(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Revenue, self::Expense => false,
        };
    }
}
