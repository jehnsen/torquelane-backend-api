<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;

/**
 * A typed-in journal voucher: an accountant's own entry (a bank charge,
 * interest, a wallet swept to the bank, an accrual, depreciation). Its lines
 * name accounts by id, and none of them is a control account.
 */
final readonly class ManualJournalFacts implements PostingFacts
{
    /**
     * @param  list<PostingLine>  $lines  accounts named by id
     */
    public function __construct(
        public string $journalId,
        public string $branchId,
        public string $entryDate,
        public string $reference,
        public string $memo,
        public array $lines,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::ManualJournal;
    }
}
