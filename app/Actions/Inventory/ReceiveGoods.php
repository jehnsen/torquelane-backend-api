<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Audit\AuditTrail;
use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Billing\Billing;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\PurchaseUnit;
use App\Domain\Inventory\ReceiptPlan;
use App\Domain\Inventory\ReceiptStatus;
use App\Domain\Inventory\StockSource;
use App\Domain\Numbering\DocumentType;
use App\Domain\Shared\Calendar;
use App\Exceptions\InvalidTransitionException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\ShopPurchaseOrder;
use App\Models\ShopPurchaseOrderLine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receive goods against a shop purchase order, in whole or in part, and void
 * a receipt that was a mistake.
 *
 * receive: one goods receipt (numbered from the `goods_receipt` series in
 * this transaction, R8) for the lines named, each no more than is still
 * outstanding. A stocked line becomes a `receipt` move into the order's
 * branch store, converted from the purchase unit to the stock unit (a case of
 * 24 → 24 each, at a 24th of the case price), so the average cost moves with
 * it. A line bought for a job never goes on the shelf: it is recorded, and
 * its cost lands on the job.
 *
 * void: R7's reversal. The receipt stays, marked void; every move it made is
 * undone by a compensating `return` move, and the purchase order's status
 * follows, since it derives from the receipts that still stand.
 */
final class ReceiveGoods
{
    public function __construct(
        private readonly ShopOrderJournal $journal,
        private readonly PostStockMove $ledger,
        private readonly StockLocations $locations,
        private readonly DocumentNumbers $numbers,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{lines: list<array{shop_purchase_order_line_id: string, quantity: string|int|float, unit_cost_cents?: int|null}>, supplier_ref?: string|null, notes?: string|null}  $data  validated
     */
    public function receive(ShopPurchaseOrder $order, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $data): GoodsReceipt {
            $locked = $this->journal->lock($order);
            $lines = [];
            foreach ($locked->lines as $orderLine) {
                $lines[$orderLine->id] = $orderLine;
            }
            $received = $this->journal->receivedByLine(array_keys($lines));
            $status = $locked->derivedStatus($received);
            if (! $status->canReceive()) {
                throw new InvalidTransitionException("Purchase order {$locked->reference} is {$status->value}; goods can be received only against an issued order that is not yet complete.");
            }

            $plan = $this->plan($data['lines'], $lines, $received);
            $location = $this->locations->storeOf($locked->branch_id);
            $now = CarbonImmutable::now();
            $actor = $this->journal->actor();

            $items = Item::query()->whereIn('id', array_values(array_filter(array_map(fn (array $p): ?string => $p['line']->item_id, $plan))))->get()->keyBy('id');
            $this->ledger->lock(array_values(array_map(
                fn (array $p): array => ['location' => $location, 'item_id' => (string) $p['line']->item_id],
                array_filter($plan, fn (array $p): bool => $p['line']->item_id !== null),
            )));

            // The receipt is an issued document: inserted once, total and all.
            $total = array_sum(array_map(fn (array $p): int => Billing::roundCents($p['quantity']->multipliedBy($p['unit_cost_cents'])), $plan));
            $receipt = new GoodsReceipt;
            $receipt->forceFill([
                'branch_id' => $locked->branch_id,
                'location_id' => $location->id,
                'shop_purchase_order_id' => $locked->id,
                'reference' => $this->numbers->issue($locked->organization_id, null, DocumentType::GoodsReceipt, $now)->formatted,
                'received_on' => Calendar::toDate($now),
                'supplier_ref' => $data['supplier_ref'] ?? null,
                'notes' => $data['notes'] ?? '',
                'total_cents' => $total,
                'received_by' => $actor->id,
                'received_by_name' => $actor->name,
            ])->save();

            foreach ($plan as $position => $row) {
                $line = $row['line'];
                $item = $line->item_id === null ? null : $items->get($line->item_id);
                $factor = $item instanceof Item ? $item->purchase_uom_factor : BigDecimal::one();
                $stockQuantity = PurchaseUnit::toStockQuantity($row['quantity'], $factor);
                $stockUnitCost = PurchaseUnit::unitCostCents($row['unit_cost_cents'], $factor);
                $lineTotal = Billing::roundCents($row['quantity']->multipliedBy($row['unit_cost_cents']));

                $receiptLine = new GoodsReceiptLine;
                $receiptLine->forceFill([
                    'goods_receipt_id' => $receipt->id,
                    'shop_purchase_order_line_id' => $line->id,
                    'position' => $position,
                    'item_id' => $line->item_id,
                    'quantity' => $row['quantity'],
                    'unit_cost_cents' => $row['unit_cost_cents'],
                    'line_total_cents' => $lineTotal,
                    'stock_quantity' => $stockQuantity,
                    'stock_unit_cost_cents' => $stockUnitCost,
                ])->save();

                if ($item instanceof Item) {
                    $this->ledger->handle($location, $item->id, new MoveRequest(MoveType::Receipt, $stockQuantity, $stockUnitCost), StockSource::GoodsReceipt, $receipt->id, null, $now);
                }
            }

            $this->audit->record($receipt, 'received', null, AuditTrail::snapshot($receipt) + ['lines' => array_values($receipt->lines()->get()->map(fn (GoodsReceiptLine $l): array => AuditTrail::snapshot($l))->all())]);
            $this->journal->audit($locked, 'goods_received', ShopOrderJournal::snapshot($locked));

            return $receipt->load('lines');
        });
    }

    public function void(GoodsReceipt $receipt, string $reason): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $reason): GoodsReceipt {
            $locked = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($locked->status !== ReceiptStatus::Posted) {
                throw new InvalidTransitionException("Goods receipt {$locked->reference} is already void.");
            }
            // The order first, then the balances: the same order every receipt locks them in.
            $order = $this->journal->lock(ShopPurchaseOrder::query()->findOrFail($locked->shop_purchase_order_id));
            $location = $this->locations->storeOf($locked->branch_id);
            $lines = $locked->lines()->get();
            $this->ledger->lock(array_values(array_map(
                fn (GoodsReceiptLine $l): array => ['location' => $location, 'item_id' => (string) $l->item_id],
                array_filter($lines->all(), fn (GoodsReceiptLine $l): bool => $l->item_id !== null),
            )));

            $before = AuditTrail::snapshot($locked);
            $now = CarbonImmutable::now();
            foreach ($lines as $line) {
                if ($line->item_id !== null) {
                    $this->ledger->handle(
                        $location,
                        $line->item_id,
                        new MoveRequest(MoveType::Return, $line->stock_quantity->negated()),
                        StockSource::GoodsReceipt,
                        $locked->id,
                        "Void of {$locked->reference}: {$reason}",
                        $now,
                    );
                }
            }

            $locked->forceFill([
                'status' => ReceiptStatus::Voided,
                'voided_at' => $now,
                'voided_by_name' => $this->journal->actor()->name,
                'void_reason' => $reason,
            ])->save();
            $this->audit->record($locked, 'voided', $before, AuditTrail::snapshot($locked));
            $this->journal->audit($order, 'goods_receipt_voided', ShopOrderJournal::snapshot($order));

            return $locked;
        });
    }

    /**
     * Each requested line, checked against the order and what is outstanding.
     *
     * @param  list<array{shop_purchase_order_line_id: string, quantity: string|int|float, unit_cost_cents?: int|null}>  $requested
     * @param  array<string, ShopPurchaseOrderLine>  $lines
     * @param  array<string, BigDecimal>  $received
     * @return list<array{line: ShopPurchaseOrderLine, quantity: BigDecimal, unit_cost_cents: int}>
     */
    private function plan(array $requested, array $lines, array $received): array
    {
        if ($requested === []) {
            throw ValidationException::withMessages(['lines' => 'Receive at least one line.']);
        }

        $plan = [];
        $seen = [];
        foreach ($requested as $position => $input) {
            $lineId = $input['shop_purchase_order_line_id'];
            $field = "lines.{$position}";
            if (! isset($lines[$lineId])) {
                throw ValidationException::withMessages(["{$field}.shop_purchase_order_line_id" => 'That line is not on this purchase order.']);
            }
            if (isset($seen[$lineId])) {
                throw ValidationException::withMessages(["{$field}.shop_purchase_order_line_id" => 'Each line is received once per receipt.']);
            }
            $seen[$lineId] = true;

            $line = $lines[$lineId];
            $quantity = BigDecimal::of((string) $input['quantity']);
            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(["{$field}.quantity" => 'Receive more than nothing.']);
            }
            $already = ($received[$lineId] ?? BigDecimal::zero());
            if (! ReceiptPlan::fits((string) $line->quantity, (string) $already, (string) $quantity)) {
                throw ValidationException::withMessages(["{$field}.quantity" => sprintf('Only %s of "%s" is still outstanding.', ReceiptPlan::remaining((string) $line->quantity, (string) $already)->strippedOfTrailingZeros(), $line->description)]);
            }
            $plan[] = ['line' => $line, 'quantity' => $quantity, 'unit_cost_cents' => $input['unit_cost_cents'] ?? $line->unit_cost_cents];
        }

        return $plan;
    }
}
