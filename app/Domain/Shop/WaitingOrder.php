<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\WorkOrders\WorkOrderFacts;

final readonly class WaitingOrder
{
    public function __construct(
        public WorkOrderFacts $order,
        /** Business hours (Mon–Fri 08:00–18:00 Manila) since it entered pending_approval. */
        public float|int $hours,
    ) {}
}
