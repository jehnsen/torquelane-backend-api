<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Access\Role;
use App\Domain\Shared\BusinessHours;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;
use App\Domain\WorkOrders\WorkOrderStatus;
use DateTimeImmutable;

/**
 * The approvals queue ("Requests" in ../web, app/(app)/requests/page.tsx):
 * what waits on the caller, what is cleared but unbooked, and spend committed
 * this month against the budget. Every order is judged against its OWN
 * effective settings (../web read one global set).
 */
final class ApprovalRequests
{
    /**
     * @param  list<RequestOrder>  $orders  the caller's scope
     */
    public static function summarise(Role $role, array $orders, int $monthlyBudgetCents, DateTimeImmutable $now): ApprovalRequestsSummary
    {
        $pending = array_values(array_filter($orders, fn (RequestOrder $o): bool => $o->status === WorkOrderStatus::PendingApproval));
        usort($pending, fn (RequestOrder $a, RequestOrder $b): int => self::sortKey($a) <=> self::sortKey($b));

        $requests = [];
        $myLines = 0;
        $myValue = 0;
        foreach ($pending as $order) {
            $value = $order->pendingValueCents();
            $waiting = $order->pendingSince === null ? 0 : BusinessHours::between($order->pendingSince, $now);
            $mine = Approvals::canApprove($role, $value, $order->settings);
            if ($mine) {
                foreach ($order->lines as $line) {
                    if ($line->status === LineApprovalStatus::Pending) {
                        $myLines++;
                        $myValue += $line->costCents;
                    }
                }
            }
            $requests[] = new PendingRequest($order, $value, $waiting, $waiting > $order->settings->slaHours, $mine);
        }

        $monthStart = Calendar::local($now)->modify('first day of this month')->setTime(0, 0);
        $committed = 0;
        $waits = [];
        $awaitingScheduling = 0;
        foreach ($orders as $order) {
            if ($order->status === WorkOrderStatus::Approved || $order->status === WorkOrderStatus::PartiallyApproved) {
                $awaitingScheduling++;
            }
            if ($order->approvalWaitHours !== null) {
                $waits[] = $order->approvalWaitHours;
            }
            foreach ($order->lines as $line) {
                if ($line->status === LineApprovalStatus::Approved && $line->approvedAt !== null && $line->approvedAt >= $monthStart) {
                    $committed += $line->costCents;
                }
            }
        }

        $avg = $waits === [] ? 0 : JsMath::round(array_sum($waits) / count($waits) * 10) / 10;

        return new ApprovalRequestsSummary(
            $requests,
            $myLines,
            $myValue,
            $awaitingScheduling,
            $committed,
            $monthlyBudgetCents,
            $monthlyBudgetCents > 0 ? (int) JsMath::round($committed / $monthlyBudgetCents * 100) : 0,
            $avg,
        );
    }

    /** ../web sorts on the ISO string, unset first. */
    private static function sortKey(RequestOrder $order): string
    {
        return $order->pendingSince === null ? '' : Calendar::local($order->pendingSince)->format('Y-m-d\TH:i:s.v');
    }
}
