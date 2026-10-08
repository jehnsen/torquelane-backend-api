<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\Approvals\ApprovalSettings;
use App\Models\WorkOrder;

/** An order with the settings it is billed and approved under. */
final readonly class WorkOrderView
{
    public function __construct(
        public WorkOrder $order,
        public ApprovalSettings $settings,
    ) {}
}
