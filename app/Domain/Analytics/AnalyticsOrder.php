<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\WorkOrders\WorkOrderStatus;

/**
 * A work order as the fleet analytics read it (already scoped by the caller).
 * Money in centavos.
 */
final readonly class AnalyticsOrder
{
    /**
     * @param  list<string>  $taskIds
     */
    public function __construct(
        public string $vehicleId,
        public WorkOrderStatus $status,
        /** preventive, corrective or inspection */
        public string $type,
        /** Y-m-d, set once closed */
        public ?string $completedOn,
        /** The estimate's aggregate parts figure (`order.partsCost`), as monthlyCosts reads it. */
        public int $partsCents,
        public int $laborCents,
        /** workOrderCost: labour plus resolved parts (itemised once recorded), rounded once. */
        public int $costCents,
        public array $taskIds,
    ) {}

    public function isClosed(): bool
    {
        return $this->status === WorkOrderStatus::Closed;
    }
}
