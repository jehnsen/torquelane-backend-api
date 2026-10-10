<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/** Which side of a journal line (and of an account) an amount sits on. */
enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
