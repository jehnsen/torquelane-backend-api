<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Domain\Inventory\StoredOrderStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\ShopPurchaseOrder;
use App\Models\ShopPurchaseOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A shop purchase order's decisions after drafting, each one transaction on
 * the locked order with its event and audit row:
 *
 *  - issue (draft → issued): the order goes to the vendor and its lines freeze;
 *  - cancel (draft | issued → cancelled), with a reason, while nothing has been
 *    received against it. Once goods are in, what remains is closed by
 *    receiving it or leaving it open; a receipt is undone by voiding it.
 *
 * "Partially received" and "received" are never decided here: they follow
 * from the goods receipts (ShopOrderStatus::derive).
 */
final class ProgressShopPurchaseOrder
{
    public function __construct(private readonly ShopOrderJournal $journal) {}

    public function issue(ShopPurchaseOrder $order): ShopPurchaseOrder
    {
        return DB::transaction(function () use ($order): ShopPurchaseOrder {
            $locked = $this->journal->lock($order);
            if ($locked->status !== StoredOrderStatus::Draft) {
                throw new InvalidTransitionException("Purchase order {$locked->reference} is already {$locked->status->value}.");
            }
            if ($locked->lines->isEmpty()) {
                throw new InvalidTransitionException('A purchase order with no lines cannot be issued.');
            }

            $before = ShopOrderJournal::snapshot($locked);
            $now = CarbonImmutable::now();
            $locked->forceFill(['status' => StoredOrderStatus::Issued, 'issued_at' => $now, 'issued_by_name' => $this->journal->actor()->name])->save();
            $this->journal->event($locked, StoredOrderStatus::Issued, $now);
            $this->journal->audit($locked, 'issued', $before);

            return $locked;
        });
    }

    public function cancel(ShopPurchaseOrder $order, string $reason): ShopPurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason): ShopPurchaseOrder {
            $locked = $this->journal->lock($order);
            $received = $this->journal->receivedByLine(array_values($locked->lines->map(fn (ShopPurchaseOrderLine $l): string => $l->id)->all()));
            $status = $locked->derivedStatus($received);
            if (! $status->canCancel()) {
                throw new InvalidTransitionException("A {$status->value} purchase order cannot be cancelled.");
            }

            $before = ShopOrderJournal::snapshot($locked);
            $now = CarbonImmutable::now();
            $locked->forceFill([
                'status' => StoredOrderStatus::Cancelled,
                'cancelled_at' => $now,
                'cancelled_by_name' => $this->journal->actor()->name,
                'cancellation_reason' => $reason,
            ])->save();
            $this->journal->event($locked, StoredOrderStatus::Cancelled, $now, $reason);
            $this->journal->audit($locked, 'cancelled', $before);

            return $locked;
        });
    }
}
