<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/**
 * The nine stored statuses (../web types WorkOrderStatus). The brief's
 * five-stage workflow is a projection over these (LifecycleStage); the extra
 * ones carry real distinctions — `partially_approved` is a genuine answer and
 * `declined` is not `cancelled` — and are never collapsed.
 */
enum WorkOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case PartiallyApproved = 'partially_approved';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Closed = 'closed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    /** `pending_approval` → `pending approval`, as the machine's messages print it. */
    public function words(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    /** Still live on the floor (../web shop.ts ACTIVE_STATUSES). */
    public function isActive(): bool
    {
        return match ($this) {
            self::Draft, self::PendingApproval, self::Approved, self::PartiallyApproved,
            self::Scheduled, self::InProgress => true,
            self::Closed, self::Declined, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }
}
