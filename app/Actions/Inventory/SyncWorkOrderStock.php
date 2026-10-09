<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Domain\Inventory\WorkOrderIssuePlan;
use App\Domain\WorkOrders\PartsSource;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\ConflictException;
use App\Models\StockMove;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Keeps the stock room in step with a work order's shop-stock lines. Call it,
 * inside the order's own transaction, wherever the lines or the order's
 * status are written (recordLines, complete, close, cancel).
 *
 * A shop-stock line owes the shelf its quantity once it is APPROVED and the
 * job is under way (in progress or closed); before that, or once the order is
 * cancelled, it owes nothing. The ledger already shows what has gone out for
 * each line (the net of its issue and return moves); the difference is posted
 * as further `issue` moves, or `return` moves back onto the shelf. Moves are
 * never edited: a change is a compensating move, and an order already in step
 * posts nothing, so calling this twice is harmless.
 *
 * What an issue costs is the average at that moment (the move's unit cost);
 * a return comes back at what the line's issues cost on average, so the
 * job's cost nets out exactly.
 */
final class SyncWorkOrderStock
{
    public function __construct(
        private readonly PostStockMove $ledger,
        private readonly StockLocations $locations,
    ) {}

    public function handle(WorkOrder $order): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Work-order stock is synced inside the order\'s transaction.');
        }

        $lines = WorkOrderLine::query()->where('work_order_id', $order->id)->get();
        $underway = $order->status === WorkOrderStatus::InProgress || $order->status === WorkOrderStatus::Closed;

        $targets = [];
        foreach ($lines as $line) {
            if ($line->parts_source === PartsSource::ShopStock && $line->item_id !== null) {
                $targets[$line->id] = [
                    'item_id' => $line->item_id,
                    'target' => $underway && $line->approval_status === LineApprovalStatus::Approved ? (string) $line->quantity : '0',
                ];
            }
        }

        $moves = StockMove::query()
            ->where('source_type', StockSource::WorkOrderLine)
            ->whereIn('source_id', array_values($lines->map(fn (WorkOrderLine $l): string => $l->id)->all()))
            ->get();
        $netIssued = [];
        $issueQuantity = [];
        $issueValue = [];
        foreach ($moves as $move) {
            $lineId = (string) $move->source_id;
            $netIssued[$lineId] ??= ['item_id' => $move->item_id, 'quantity' => BigDecimal::zero()];
            $netIssued[$lineId]['quantity'] = $netIssued[$lineId]['quantity']->minus($move->quantity);
            if ($move->move_type === MoveType::Issue) {
                $issueQuantity[$lineId] = ($issueQuantity[$lineId] ?? BigDecimal::zero())->plus($move->quantity->abs());
                $issueValue[$lineId] = ($issueValue[$lineId] ?? BigDecimal::zero())->plus($move->quantity->abs()->multipliedBy($move->unit_cost_cents));
            }
        }

        $deltas = WorkOrderIssuePlan::deltas(
            $targets,
            array_map(fn (array $net): array => ['item_id' => $net['item_id'], 'quantity' => (string) $net['quantity']], $netIssued),
        );
        if ($deltas === []) {
            return;
        }

        $branchId = $order->branch_id ?? $order->assigned_branch_id;
        if ($branchId === null) {
            throw new ConflictException('This work order has not been taken into a branch, so there is no stock room to issue from.');
        }
        $location = $this->locations->storeOf($branchId);
        $this->ledger->lock(array_map(fn (array $d): array => ['location' => $location, 'item_id' => $d['item_id']], $deltas));

        $now = CarbonImmutable::now();
        foreach ($deltas as $delta) {
            $lineId = $delta['line_id'];
            if ($delta['quantity']->isNegative()) {
                $this->ledger->handle($location, $delta['item_id'], new MoveRequest(MoveType::Issue, $delta['quantity']), StockSource::WorkOrderLine, $lineId, "Issued to {$this->label($order)}", $now);

                continue;
            }

            $unitCost = isset($issueQuantity[$lineId]) && $issueQuantity[$lineId]->isPositive()
                ? $issueValue[$lineId]->dividedBy($issueQuantity[$lineId], 0, RoundingMode::HalfUp)->toInt()
                : null;
            $this->ledger->handle($location, $delta['item_id'], new MoveRequest(MoveType::Return, $delta['quantity'], $unitCost), StockSource::WorkOrderLine, $lineId, "Returned from {$this->label($order)}", $now);
        }
    }

    private function label(WorkOrder $order): string
    {
        return $order->reference !== '' ? $order->reference : 'a work order';
    }
}
