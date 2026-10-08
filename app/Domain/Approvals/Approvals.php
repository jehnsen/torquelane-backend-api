<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Access\Role;
use App\Domain\Billing\BillableLine;
use App\Domain\WorkOrders\WorkOrderStatus;
use Brick\Math\BigDecimal;

/**
 * Port of ../web/lib/approvals.ts, in centavos. Line values read the STORED
 * line costs (the amount the customer saw), never re-derived from rates.
 */
final class Approvals
{
    /**
     * @param  list<BillableLine>  $lines
     */
    public static function sumByStatus(array $lines, LineApprovalStatus $status): int
    {
        $total = 0;
        foreach ($lines as $line) {
            if ($line->approvalStatus === $status) {
                $total += $line->cost();
            }
        }

        return $total;
    }

    /** @param  list<BillableLine>  $lines */
    public static function pendingValue(array $lines): int
    {
        return self::sumByStatus($lines, LineApprovalStatus::Pending);
    }

    /** @param  list<BillableLine>  $lines */
    public static function approvedValue(array $lines): int
    {
        return self::sumByStatus($lines, LineApprovalStatus::Approved);
    }

    /** @param  list<BillableLine>  $lines */
    public static function declinedValue(array $lines): int
    {
        return self::sumByStatus($lines, LineApprovalStatus::Declined);
    }

    /** Strictly under the auto ceiling is automatic; up to and including the ops ceiling is operations'. */
    public static function requiredApprover(int $pendingCents, ApprovalSettings $settings): ApproverBand
    {
        if ($pendingCents < $settings->autoApproveUnderCents) {
            return ApproverBand::Auto;
        }
        if ($pendingCents <= $settings->opsApprovalUnderCents) {
            return ApproverBand::Operations;
        }

        return ApproverBand::FleetManager;
    }

    /**
     * Authority beyond the `workorder:approve` capability. A branch manager
     * approves without limit, like the provider admin, within the branches
     * they are pinned to (scope, not this rule, keeps them there).
     */
    public static function canApprove(Role $role, int $pendingCents, ApprovalSettings $settings): bool
    {
        return match ($role) {
            Role::FleetManager, Role::ProviderAdmin, Role::BranchManager => true,
            Role::Operations, Role::PurchasingOfficer => $pendingCents <= $settings->opsApprovalUnderCents,
            default => false,
        };
    }

    /**
     * The order's status from its lines' answers: any line still pending keeps
     * it pending; all approved is approved; none approved (declined and
     * deferred alike) is declined; anything else is partially approved.
     *
     * @param  list<LineApprovalStatus>  $statuses
     */
    public static function deriveOrderStatus(array $statuses): WorkOrderStatus
    {
        if ($statuses === []) {
            return WorkOrderStatus::Approved;
        }
        if (in_array(LineApprovalStatus::Pending, $statuses, true)) {
            return WorkOrderStatus::PendingApproval;
        }
        $approved = count(array_filter($statuses, fn (LineApprovalStatus $s): bool => $s === LineApprovalStatus::Approved));

        return match (true) {
            $approved === count($statuses) => WorkOrderStatus::Approved,
            $approved === 0 => WorkOrderStatus::Declined,
            default => WorkOrderStatus::PartiallyApproved,
        };
    }

    /** Actual spend beyond approved × (1 + pct/100) needs a second sign-off before close-out. Exact. */
    public static function varianceExceeds(int $approvedCents, int $actualCents, string|int $thresholdPct): bool
    {
        $limit = BigDecimal::of($approvedCents)->multipliedBy(BigDecimal::of(100)->plus($thresholdPct));

        return BigDecimal::of($actualCents)->multipliedBy(100)->isGreaterThan($limit);
    }
}
