<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/** The debits and credits an account has taken over some range (optionally in one branch): the raw input to every report. */
final readonly class AccountBalance
{
    public function __construct(
        public string $accountId,
        public string $code,
        public string $name,
        public AccountType $type,
        public Side $normalSide,
        public int $debitCents,
        public int $creditCents,
        public ?string $branchId = null,
    ) {}

    /** Debits less credits. */
    public function netDebitCents(): int
    {
        return $this->debitCents - $this->creditCents;
    }

    /** The balance on the account's own side: positive when it holds what it normally holds. */
    public function balanceCents(): int
    {
        return $this->normalSide === Side::Debit ? $this->netDebitCents() : -$this->netDebitCents();
    }
}
