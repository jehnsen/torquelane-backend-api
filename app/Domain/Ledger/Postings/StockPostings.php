<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Inventory\ItemType;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\RuleKey;
use InvalidArgumentException;

/**
 * Stock events, in a perpetual-inventory ledger:
 *
 *  - goods received:   Dr Inventory / Cr GR/IR Clearing (the vendor bill clears it later)
 *  - receipt voided:   Dr GR/IR Clearing / Cr Inventory
 *  - parts issued:     Dr COGS (by item type) / Cr Inventory
 *  - parts returned:   Dr Inventory / Cr COGS
 *  - count variance:   Dr Inventory / Cr Inventory Adjustments (a gain), or the reverse
 *  - opening balance:  Dr Inventory / Cr Opening Balance Equity
 *  - transfer:         Dr Inventory (destination branch) / Cr Inventory (source branch)
 *
 * The Inventory line is always the change in the balance's BOOK value, so the
 * Inventory account equals the stock room's valuation to the centavo. The
 * other side is the move's value at its own cost. Where the two differ (the
 * moving average is rounded to a centavo, which revalues the stock already
 * on the shelf), the difference is posted to Inventory Adjustments as a
 * visible costing-rounding line, never hidden.
 */
final class StockPostings
{
    public static function move(StockFacts $facts): JournalDraft
    {
        $branch = $facts->branchId;
        $delta = $facts->bookDeltaCents;
        $value = $facts->valueCents;
        $memo = $facts->memo;

        $inventory = $delta >= 0
            ? PostingLine::debit(RuleKey::Inventory, $delta, $branch, stockMoveId: $facts->stockMoveId, memo: $memo)
            : PostingLine::credit(RuleKey::Inventory, -$delta, $branch, stockMoveId: $facts->stockMoveId, memo: $memo);

        $counter = match ($facts->event) {
            LedgerEvent::StockOpening => PostingLine::credit(RuleKey::OpeningBalanceEquity, $value, $branch, memo: $memo),
            LedgerEvent::StockReceipt => PostingLine::credit(RuleKey::GoodsReceivedClearing, $value, $branch, memo: $memo),
            LedgerEvent::StockReceiptReturn => PostingLine::debit(RuleKey::GoodsReceivedClearing, $value, $branch, memo: $memo),
            LedgerEvent::StockIssue, LedgerEvent::StockConsumption => PostingLine::debit(self::costOf($facts->itemType), $value, $branch, memo: $memo),
            LedgerEvent::StockReturn => PostingLine::credit(self::costOf($facts->itemType), $value, $branch, memo: $memo),
            LedgerEvent::StockAdjustment => $delta >= 0
                ? PostingLine::credit(RuleKey::InventoryAdjustments, $delta, $branch, memo: $memo)
                : PostingLine::debit(RuleKey::InventoryAdjustments, -$delta, $branch, memo: $memo),
            default => throw new InvalidArgumentException("{$facts->event->value} is not a single stock move."),
        };

        return new JournalDraft(
            $facts->event,
            $facts->entryDate,
            $branch,
            $facts->stockMoveId,
            $facts->reference,
            $memo,
            self::withRounding([$inventory, $counter], $branch),
        );
    }

    public static function transfer(TransferFacts $facts): JournalDraft
    {
        $out = $facts->outDeltaCents >= 0
            ? PostingLine::debit(RuleKey::Inventory, $facts->outDeltaCents, $facts->fromBranchId, stockMoveId: $facts->outMoveId, memo: $facts->memo)
            : PostingLine::credit(RuleKey::Inventory, -$facts->outDeltaCents, $facts->fromBranchId, stockMoveId: $facts->outMoveId, memo: $facts->memo);
        $in = $facts->inDeltaCents >= 0
            ? PostingLine::debit(RuleKey::Inventory, $facts->inDeltaCents, $facts->toBranchId, stockMoveId: $facts->inMoveId, memo: $facts->memo)
            : PostingLine::credit(RuleKey::Inventory, -$facts->inDeltaCents, $facts->toBranchId, stockMoveId: $facts->inMoveId, memo: $facts->memo);

        return new JournalDraft(
            LedgerEvent::StockTransfer,
            $facts->entryDate,
            $facts->fromBranchId,
            $facts->outMoveId,
            $facts->reference,
            $facts->memo,
            self::withRounding([$in, $out], $facts->fromBranchId),
            $facts->toBranchId === $facts->fromBranchId ? null : $facts->toBranchId,
        );
    }

    public static function costOf(ItemType $type): RuleKey
    {
        return match ($type) {
            ItemType::Part, ItemType::Retail, ItemType::ServiceFee => RuleKey::CogsParts,
            ItemType::Consumable => RuleKey::CogsConsumables,
            ItemType::Ingredient => RuleKey::CogsCafe,
        };
    }

    /**
     * Adds the costing-rounding line that makes the entry balance.
     *
     * @param  list<PostingLine>  $lines
     * @return list<PostingLine>
     */
    private static function withRounding(array $lines, string $branchId): array
    {
        $difference = 0;
        foreach ($lines as $line) {
            $difference += $line->debitCents - $line->creditCents;
        }
        if ($difference > 0) {
            $lines[] = PostingLine::credit(RuleKey::InventoryAdjustments, $difference, $branchId, memo: 'Average-cost rounding');
        } elseif ($difference < 0) {
            $lines[] = PostingLine::debit(RuleKey::InventoryAdjustments, -$difference, $branchId, memo: 'Average-cost rounding');
        }

        return $lines;
    }
}
