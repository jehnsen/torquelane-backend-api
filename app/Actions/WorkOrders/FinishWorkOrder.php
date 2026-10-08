<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * markCollected (revenue is recognised here, not at close) and cancel.
 */
final class FinishWorkOrder
{
    public function __construct(private readonly WorkOrderJournal $journal) {}

    public function markCollected(WorkOrder $order): WorkOrder
    {
        return DB::transaction(function () use ($order): WorkOrder {
            $locked = $this->journal->lock($order);
            if ($locked->status !== WorkOrderStatus::Closed || $locked->collected_at !== null) {
                throw new InvalidTransitionException('Only a closed job that has not been collected can be collected.');
            }
            $before = WorkOrderJournal::snapshot($locked);

            $locked->forceFill(['collected_at' => CarbonImmutable::now(), 'collected_by' => $this->journal->actor()->id])->save();
            $this->journal->audit($locked, 'collected', $before);

            return $locked;
        });
    }

    public function cancel(WorkOrder $order, string $reason): WorkOrder
    {
        return DB::transaction(function () use ($order, $reason): WorkOrder {
            $locked = $this->journal->lock($order);
            $this->journal->guard($locked, WorkOrderStatus::Cancelled);
            $before = WorkOrderJournal::snapshot($locked);

            $locked->forceFill(['status' => WorkOrderStatus::Cancelled, 'cancellation_reason' => $reason])->save();
            $this->journal->event($locked, WorkOrderStatus::Cancelled, CarbonImmutable::now());
            $this->journal->audit($locked, 'cancelled', $before);

            return $locked;
        });
    }
}
