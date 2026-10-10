<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;

/** One line of a stock transfer: the goods leave one branch's books and reach another's. No profit or loss. */
final readonly class TransferFacts implements PostingFacts
{
    public function __construct(
        public string $outMoveId,
        public string $inMoveId,
        public string $fromBranchId,
        public string $toBranchId,
        public string $entryDate,
        /** Book-value change of the source balance (negative or zero). */
        public int $outDeltaCents,
        /** Book-value change of the destination balance (positive or zero). */
        public int $inDeltaCents,
        public string $reference,
        public string $memo,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::StockTransfer;
    }
}
