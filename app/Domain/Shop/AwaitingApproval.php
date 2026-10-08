<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\WorkOrders\WorkOrderFacts;

final readonly class AwaitingApproval
{
    /**
     * @param  list<WorkOrderFacts>  $orders
     */
    public function __construct(
        public array $orders,
        public int $count,
        public int $totalValueCents,
        public ?WaitingOrder $longest,
    ) {}
}
