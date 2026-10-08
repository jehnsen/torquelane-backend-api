<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use DateTimeImmutable;

/** One entry of a work order's append-only status history. */
final readonly class StatusEvent
{
    public function __construct(
        public WorkOrderStatus $status,
        public DateTimeImmutable $at,
    ) {}
}
