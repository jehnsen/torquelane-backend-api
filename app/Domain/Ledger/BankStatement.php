<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * A bank reconciliation's arithmetic. The statement's closing balance should
 * equal everything the bank has cleared on the account: what earlier
 * reconciliations cleared, plus the lines ticked on this one. What is in the
 * books but not yet on a statement (a cheque not presented, a deposit in
 * transit) is outstanding, and explains the gap to the book balance.
 *
 *   cleared      = cleared before + ticked now
 *   difference   = statement balance − cleared        (zero to complete)
 *   book balance = cleared + outstanding
 *
 * Amounts are debits less credits, in centavos: money in is positive.
 */
final readonly class BankStatement
{
    public function __construct(
        public int $statementBalanceCents,
        public int $clearedBeforeCents,
        public int $tickedCents,
        public int $outstandingCents,
    ) {}

    public function clearedCents(): int
    {
        return $this->clearedBeforeCents + $this->tickedCents;
    }

    public function differenceCents(): int
    {
        return $this->statementBalanceCents - $this->clearedCents();
    }

    public function bookBalanceCents(): int
    {
        return $this->clearedCents() + $this->outstandingCents;
    }

    public function agrees(): bool
    {
        return $this->differenceCents() === 0;
    }
}
