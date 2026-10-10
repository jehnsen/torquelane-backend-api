<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;

/**
 * A void: the same accounts and amounts as the entry it undoes, on the other
 * side, dated the day it happened (a void never rewrites history, so the
 * period of the original may long be closed).
 */
final readonly class ReversalFacts implements PostingFacts
{
    /**
     * @param  list<PostingLine>  $original  the undone entry's lines, accounts named by id
     */
    public function __construct(
        public LedgerEvent $event,
        public string $sourceId,
        public string $reversalOfId,
        public string $reversedNumber,
        public string $reference,
        public string $branchId,
        public ?string $counterBranchId,
        public ?string $paymentMethod,
        public string $entryDate,
        public string $reason,
        public array $original,
    ) {}

    public function event(): LedgerEvent
    {
        return $this->event;
    }
}
