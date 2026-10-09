<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

/** How a payment came in. Everything but cash carries a reference number. */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Gcash = 'gcash';
    case Maya = 'maya';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Check = 'check';

    public function needsReference(): bool
    {
        return $this !== self::Cash;
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Gcash => 'GCash',
            self::Maya => 'Maya',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Check => 'Check',
        };
    }
}
