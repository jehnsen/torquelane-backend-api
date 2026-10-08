<?php

declare(strict_types=1);

namespace App\Domain\Parts;

use App\Domain\WorkOrders\WorkOrderStatus;

/** A work order as the forecast reads it: which due items it already covers. */
final readonly class WorkCoverage
{
    /**
     * @param  list<string>  $taskIds
     */
    public function __construct(
        public WorkOrderStatus $status,
        public string $vehicleId,
        public array $taskIds,
    ) {}
}
