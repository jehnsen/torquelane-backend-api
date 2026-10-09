<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use App\Domain\Access\Capability;

/**
 * Port of ../web/lib/work-order-machine.ts: the one place a status change is
 * legal or not. Every action asks `checkTransition` before writing; nothing
 * else decides. It returns the capability the move needs rather than
 * resolving it, so it stays pure.
 */
final class WorkOrderMachine
{
    /**
     * ../web's projection, which knew no invoices: a closed job is completed
     * once collected. Phase 7 reads it through `billingStage` (an order never
     * invoiced, settled = collected), so the golden replay still holds.
     */
    public static function lifecycleStage(WorkOrderStatus $status, bool $collected): LifecycleStage
    {
        return self::billingStage($status, false, $collected);
    }

    /**
     * The stage with invoicing (Phase 7). A closed job is:
     *  - completed once settled: its invoice is paid (`collected_at` is
     *    stamped then), or it was collected before invoices existed;
     *  - invoiced while a standing (issued, not void) invoice carries it;
     *  - ready for billing otherwise: closed and not invoiced.
     */
    public static function billingStage(WorkOrderStatus $status, bool $invoiced, bool $settled): LifecycleStage
    {
        return match ($status) {
            WorkOrderStatus::Draft => LifecycleStage::Draft,
            WorkOrderStatus::PendingApproval => LifecycleStage::PendingApproval,
            WorkOrderStatus::Approved, WorkOrderStatus::PartiallyApproved => LifecycleStage::Approved,
            WorkOrderStatus::Scheduled, WorkOrderStatus::InProgress => LifecycleStage::InProgress,
            WorkOrderStatus::Closed => match (true) {
                $settled => LifecycleStage::Completed,
                $invoiced => LifecycleStage::Invoiced,
                default => LifecycleStage::ReadyForBilling,
            },
            WorkOrderStatus::Declined => LifecycleStage::Declined,
            WorkOrderStatus::Cancelled => LifecycleStage::Cancelled,
        };
    }

    public static function canTransition(WorkOrderStatus $from, WorkOrderStatus $to): bool
    {
        return in_array($to, self::nextStatuses($from), true);
    }

    /**
     * @return list<WorkOrderStatus>
     */
    public static function nextStatuses(WorkOrderStatus $from): array
    {
        return match ($from) {
            WorkOrderStatus::Draft => [WorkOrderStatus::PendingApproval, WorkOrderStatus::Approved, WorkOrderStatus::Cancelled],
            WorkOrderStatus::PendingApproval => [WorkOrderStatus::Approved, WorkOrderStatus::PartiallyApproved, WorkOrderStatus::Declined, WorkOrderStatus::Cancelled],
            WorkOrderStatus::Approved,
            WorkOrderStatus::PartiallyApproved => [WorkOrderStatus::Scheduled, WorkOrderStatus::InProgress, WorkOrderStatus::Cancelled],
            WorkOrderStatus::Scheduled => [WorkOrderStatus::InProgress, WorkOrderStatus::Cancelled],
            WorkOrderStatus::InProgress => [WorkOrderStatus::Closed, WorkOrderStatus::Cancelled],
            WorkOrderStatus::Declined => [WorkOrderStatus::Draft, WorkOrderStatus::Cancelled],
            WorkOrderStatus::Closed, WorkOrderStatus::Cancelled => [],
        };
    }

    public static function capabilityFor(WorkOrderStatus $to): ?Capability
    {
        return match ($to) {
            WorkOrderStatus::PendingApproval, WorkOrderStatus::Scheduled,
            WorkOrderStatus::InProgress, WorkOrderStatus::Cancelled => Capability::WorkOrderUpdate,
            WorkOrderStatus::Approved, WorkOrderStatus::PartiallyApproved,
            WorkOrderStatus::Declined => Capability::WorkOrderApprove,
            WorkOrderStatus::Closed => Capability::WorkOrderComplete,
            WorkOrderStatus::Draft => null,
        };
    }

    public static function checkTransition(WorkOrderStatus $from, int $lineCount, WorkOrderStatus $to): TransitionCheck
    {
        if ($from === $to) {
            return TransitionCheck::deny(sprintf('This work order is already %s.', mb_strtolower(self::lifecycleStage($to, false)->label())));
        }
        if (! self::canTransition($from, $to)) {
            return TransitionCheck::deny(sprintf('A %s work order cannot become %s.', $from->words(), $to->words()));
        }
        if ($to === WorkOrderStatus::PendingApproval && $lineCount === 0) {
            return TransitionCheck::deny('Add at least one line before sending this for approval.');
        }

        return TransitionCheck::allow(self::capabilityFor($to));
    }

    /**
     * Who carries out approved work: the branch that approved it. `vendor`
     * stays reserved for a genuine third-party subcontractor — writing the
     * shop's own name there would put it in its own vendor-spend figures.
     *
     * @return array{assigned_branch_id: string, vendor: string}
     */
    public static function assignOnApproval(string $vendor, string $branchId): array
    {
        return ['assigned_branch_id' => $branchId, 'vendor' => $vendor];
    }

    public static function isInHouse(string $vendor): bool
    {
        return trim($vendor) === '';
    }
}
