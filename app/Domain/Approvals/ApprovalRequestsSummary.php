<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

final readonly class ApprovalRequestsSummary
{
    /**
     * @param  list<PendingRequest>  $pending  oldest first
     */
    public function __construct(
        public array $pending,
        /** Pending lines on orders the caller may approve. */
        public int $myPendingLineCount,
        public int $myPendingValueCents,
        /** Approved or partially approved, not yet booked. */
        public int $awaitingScheduling,
        /** Lines approved since the start of this (Manila) month. */
        public int $committedThisPeriodCents,
        public int $monthlyBudgetCents,
        /** committed / budget, rounded; 0 without a budget. */
        public int $budgetUsedPct,
        /** Mean business hours from raised to decided, to a tenth; 0 when none decided. */
        public float|int $avgTurnaroundHours,
    ) {}
}
