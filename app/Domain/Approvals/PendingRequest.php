<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

final readonly class PendingRequest
{
    public function __construct(
        public RequestOrder $order,
        public int $pendingValueCents,
        /** Business hours waited so far. */
        public float|int $waitingHours,
        /** Waited past its SLA. */
        public bool $breached,
        /** Whether the caller's role may decide it (its pending value within their band). */
        public bool $canApprove,
    ) {}
}
