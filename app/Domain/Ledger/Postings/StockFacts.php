<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Inventory\ItemType;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Domain\Ledger\LedgerEvent;

/**
 * One stock move as the ledger sees it.
 *
 * `bookDeltaCents` is the change in the balance's book value (on hand ×
 * average cost, rounded to a centavo) the move caused: what the Inventory
 * account moves by, so it always equals the stock room's valuation.
 * `valueCents` is what the move was worth at its own cost, the other side of
 * the entry. They differ by centavos when the average rounds.
 */
final readonly class StockFacts implements PostingFacts
{
    public function __construct(
        public LedgerEvent $event,
        public string $stockMoveId,
        public string $branchId,
        public string $entryDate,
        public ItemType $itemType,
        public int $bookDeltaCents,
        public int $valueCents,
        public string $reference,
        public string $memo,
    ) {}

    public function event(): LedgerEvent
    {
        return $this->event;
    }

    /** Which ledger event a move is. A transfer is not here: its two moves are one entry (TransferFacts). */
    public static function eventFor(MoveType $type, StockSource $source, bool $inbound): LedgerEvent
    {
        return match ($type) {
            MoveType::Opening => LedgerEvent::StockOpening,
            MoveType::Receipt => LedgerEvent::StockReceipt,
            MoveType::Issue => LedgerEvent::StockIssue,
            MoveType::Consumption => LedgerEvent::StockConsumption,
            MoveType::Adjustment => LedgerEvent::StockAdjustment,
            MoveType::Return => $inbound ? LedgerEvent::StockReturn : LedgerEvent::StockReceiptReturn,
            MoveType::TransferOut, MoveType::TransferIn => LedgerEvent::StockTransfer,
        };
    }
}
