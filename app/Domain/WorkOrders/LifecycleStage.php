<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/** The workflow's stages (../web work-order-machine.ts LifecycleStage, STAGE_LABEL). */
enum LifecycleStage: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case ReadyForBilling = 'ready_for_billing';
    /** Phase 7: on a standing invoice that is not yet paid. */
    case Invoiced = 'invoiced';
    case Completed = 'completed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::InProgress => 'In progress',
            self::ReadyForBilling => 'Ready for billing',
            self::Invoiced => 'Invoiced',
            self::Completed => 'Completed',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
        };
    }
}
