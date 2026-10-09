<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockSource;
use App\Domain\WorkOrders\PartsSource;
use App\Models\GoodsReceiptLine;
use App\Models\ShopPurchaseOrderLine;
use App\Models\StockMove;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Brick\Math\BigDecimal;

/**
 * What a job's parts COST the shop, against what the customer is charged
 * for them. The price is the approved line's stored part amount and is never
 * touched here. The cost is what actually moved:
 *
 *  - a shop-stock line costs its stock moves (issues less returns), each at
 *    the unit cost it moved at;
 *  - a line purchased for the job costs the goods received against the
 *    purchase-order lines that name it (receipts still standing).
 *
 * Other sources have no cost the ledger knows, and are left out. Sums are
 * exact and rounded once per figure (R6).
 */
final class JobStockCosts
{
    /**
     * @return array{lines: array<string, int>, cost_cents: int, price_cents: int, margin_cents: int}|null null when the job has no ledger-costed parts; `lines` holds only the lines something has moved or arrived for
     */
    public function forOrder(WorkOrder $order): ?array
    {
        $costed = $order->lines->filter(fn (WorkOrderLine $l): bool => $l->parts_source === PartsSource::ShopStock || $l->parts_source === PartsSource::PurchasedForJob);
        if ($costed->isEmpty()) {
            return null;
        }

        $moves = StockMove::query()
            ->where('source_type', StockSource::WorkOrderLine)
            ->whereIn('source_id', $costed->filter(fn (WorkOrderLine $l): bool => $l->parts_source === PartsSource::ShopStock)->pluck('id')->all())
            ->get(['source_id', 'quantity', 'unit_cost_cents']);
        $moveCost = [];
        foreach ($moves as $move) {
            $moveCost[(string) $move->source_id] = ($moveCost[(string) $move->source_id] ?? BigDecimal::zero())->plus($move->quantity->negated()->multipliedBy($move->unit_cost_cents));
        }

        $purchased = [];
        $orderLines = ShopPurchaseOrderLine::query()->whereIn('work_order_line_id', $costed->filter(fn (WorkOrderLine $l): bool => $l->parts_source === PartsSource::PurchasedForJob)->pluck('id')->all())->get(['id', 'work_order_line_id']);
        if ($orderLines->isNotEmpty()) {
            $owner = [];
            foreach ($orderLines as $orderLine) {
                if ($orderLine->work_order_line_id !== null) {
                    $owner[$orderLine->id] = $orderLine->work_order_line_id;
                }
            }
            $receipts = GoodsReceiptLine::query()
                ->whereIn('shop_purchase_order_line_id', $orderLines->pluck('id')->all())
                ->whereHas('receipt', fn ($receipt) => $receipt->where('status', 'posted'))
                ->get(['shop_purchase_order_line_id', 'line_total_cents']);
            foreach ($receipts as $receipt) {
                $lineId = $owner[$receipt->shop_purchase_order_line_id] ?? null;
                if ($lineId !== null) {
                    $purchased[$lineId] = ($purchased[$lineId] ?? 0) + $receipt->line_total_cents;
                }
            }
        }

        $perLine = [];
        $exact = BigDecimal::zero();
        $price = 0;
        foreach ($costed as $line) {
            $cost = $line->parts_source === PartsSource::ShopStock
                ? ($moveCost[$line->id] ?? BigDecimal::zero())
                : BigDecimal::of($purchased[$line->id] ?? 0);
            // A line nothing has moved or arrived for has no cost yet (not a cost of nothing).
            if (isset($moveCost[$line->id]) || isset($purchased[$line->id])) {
                $perLine[$line->id] = StockLedger::roundCents($cost);
            }
            $exact = $exact->plus($cost);
            if ($line->approval_status === LineApprovalStatus::Approved) {
                $price += $line->part_cost_cents;
            }
        }
        $total = StockLedger::roundCents($exact);

        return ['lines' => $perLine, 'cost_cents' => $total, 'price_cents' => $price, 'margin_cents' => $price - $total];
    }
}
