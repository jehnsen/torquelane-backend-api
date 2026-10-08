<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\WorkOrders\WorkOrderStatus;
use DateTimeImmutable;

/** A work order as the approvals queue reads it, with its own effective settings. */
final readonly class RequestOrder
{
    /**
     * @param  list<RequestLine>  $lines
     */
    public function __construct(
        public string $id,
        public WorkOrderStatus $status,
        public ?DateTimeImmutable $pendingSince,
        /** Business hours from raised to decided, once decided. */
        public float|int|null $approvalWaitHours,
        public array $lines,
        public ApprovalSettings $settings,
    ) {}

    public function pendingValueCents(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            if ($line->status === LineApprovalStatus::Pending) {
                $total += $line->costCents;
            }
        }

        return $total;
    }
}
