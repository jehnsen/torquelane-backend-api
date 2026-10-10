<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * An entry before it is written: what an event posts, as plain data. It
 * cannot be built unbalanced (UnbalancedEntry), so nothing downstream ever
 * holds a draft that would fail at commit.
 */
final readonly class JournalDraft
{
    public int $totalCents;

    /**
     * @param  list<PostingLine>  $lines
     */
    public function __construct(
        public LedgerEvent $event,
        /** Business date, Asia/Manila. */
        public string $entryDate,
        public string $branchId,
        public string $sourceId,
        public string $reference,
        public string $memo,
        public array $lines,
        public ?string $counterBranchId = null,
        public ?string $paymentMethod = null,
        public ?string $reversalOfId = null,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) !== 1) {
            throw new InvalidArgumentException("Entry date [{$entryDate}] is not a business date.");
        }
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            $debits += $line->debitCents;
            $credits += $line->creditCents;
        }
        if (count($lines) < 2 || $debits !== $credits) {
            throw UnbalancedEntry::of($event, $debits, $credits, count($lines));
        }
        $this->totalCents = $debits;
    }

    public function sourceType(): string
    {
        return $this->event->sourceType();
    }
}
