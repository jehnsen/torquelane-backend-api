<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

/** What an approval_log row records (../web ApprovalAction). */
enum ApprovalAction: string
{
    case SentForApproval = 'sent_for_approval';
    case AutoApproved = 'auto_approved';
    case Approved = 'approved';
    case Declined = 'declined';
    case Deferred = 'deferred';
    case Escalated = 'escalated';
    case VarianceApproved = 'variance_approved';

    public static function forDecision(LineApprovalStatus $decision): self
    {
        return match ($decision) {
            LineApprovalStatus::Approved => self::Approved,
            LineApprovalStatus::Declined => self::Declined,
            LineApprovalStatus::Deferred => self::Deferred,
            LineApprovalStatus::Pending => throw new \InvalidArgumentException('Pending is not a decision.'),
        };
    }
}
