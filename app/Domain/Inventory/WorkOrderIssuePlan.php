<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/**
 * What a work order owes the stock room. For every shop-stock line the target
 * is the quantity that should be out of the shelf (the line's quantity once
 * it is approved and the job is under way; nothing otherwise, or once the
 * order is cancelled). The plan is the difference between that target and
 * what the ledger already shows issued: a negative delta is a further issue,
 * a positive one a return. Moves are never edited, so a change is a
 * compensating move, and a plan against an unchanged order is empty.
 */
final class WorkOrderIssuePlan
{
    /**
     * @param  array<string, array{item_id: string, target: string}>  $lines  keyed by work-order line id
     * @param  array<string, array{item_id: string, quantity: string}>  $netIssued  per line id, the stock units already out (positive = issued)
     * @return list<array{line_id: string, item_id: string, quantity: BigDecimal}> signed: negative issues, positive returns
     */
    public static function deltas(array $lines, array $netIssued): array
    {
        $deltas = [];
        foreach ($lines as $lineId => $line) {
            $gap = BigDecimal::of($netIssued[$lineId]['quantity'] ?? '0')->minus($line['target']);
            if (! $gap->isZero()) {
                $deltas[] = ['line_id' => $lineId, 'item_id' => $line['item_id'], 'quantity' => $gap];
            }
        }
        // A line that was issued and has since left the order (or stopped being
        // shop stock) is returned in full.
        foreach ($netIssued as $lineId => $out) {
            $quantity = BigDecimal::of($out['quantity']);
            if (! isset($lines[$lineId]) && ! $quantity->isZero()) {
                $deltas[] = ['line_id' => $lineId, 'item_id' => $out['item_id'], 'quantity' => $quantity];
            }
        }

        return $deltas;
    }
}
