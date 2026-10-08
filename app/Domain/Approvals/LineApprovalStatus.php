<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

/**
 * A line's answer. Approval is per line, not per order — "do the brakes,
 * skip the shocks" is a real answer — and the order's status is derived from
 * these (Approvals::deriveOrderStatus), never set directly.
 */
enum LineApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Deferred = 'deferred';
}
