<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\AccountBalance;
use App\Domain\Ledger\LedgerEvent;

/**
 * The year-end closing entry: every revenue and expense balance of the year,
 * per branch, turned back to zero, and the difference (the year's profit or
 * loss, per branch) moved to Retained Earnings.
 *
 * It is dated 31 December of the year it closes, unless that month was
 * already closed when the year was (a closed period takes nothing): then it
 * is dated the first day still open, and says so.
 */
final readonly class YearCloseFacts implements PostingFacts
{
    /**
     * @param  list<AccountBalance>  $balances  revenue and expense accounts over the year, by branch, before any closing entry
     */
    public function __construct(
        public string $fiscalYearId,
        public int $year,
        /** The branch the entry is filed under (its lines carry their own). */
        public string $branchId,
        public array $balances,
        public string $entryDate,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::YearClosed;
    }
}
