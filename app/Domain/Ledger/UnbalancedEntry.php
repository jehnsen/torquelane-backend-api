<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use DomainException;

/** A draft whose debits and credits differ (or that has fewer than two lines). Thrown before anything is written. */
final class UnbalancedEntry extends DomainException
{
    public static function of(LedgerEvent $event, int $debits, int $credits, int $lines): self
    {
        return $lines < 2
            ? new self("A {$event->value} entry needs at least two lines; it has {$lines}.")
            : new self("A {$event->value} entry does not balance: debits {$debits}, credits {$credits}.");
    }
}
