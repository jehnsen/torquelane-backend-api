<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\Approvals\ApprovalAction;
use App\Domain\Approvals\Approvals;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Shared\BusinessHours;
use App\Domain\Shared\JsMath;
use App\Domain\WorkOrders\LineUrgency;
use App\Domain\WorkOrders\WorkOrderMachine;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer's answer, per line: approve, decline or defer. The order's
 * status is derived from the lines (never set), the wait is stamped in
 * business hours when the order leaves pending, and approved work is assigned
 * to the order's branch. Lines are decided only while the order is
 * pending_approval — approving a draft would skip its numbering.
 */
final class DecideLines
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly WorkOrderJournal $journal,
        private readonly ApprovalSettingsResolver $settings,
    ) {}

    /**
     * @param  list<array{line_id: string, decision: string, note?: string|null}>  $decisions
     */
    public function handle(WorkOrder $order, array $decisions): WorkOrder
    {
        $context = $this->tenancy->require();

        return DB::transaction(function () use ($context, $order, $decisions): WorkOrder {
            $locked = $this->journal->lock($order);
            if ($locked->status !== WorkOrderStatus::PendingApproval) {
                throw new InvalidTransitionException('Lines are decided only while the quotation is pending approval.');
            }

            $settings = $this->settings->forOrder($locked);
            $pending = Approvals::pendingValue(array_values($locked->lines->map(fn (WorkOrderLine $l) => $l->billable())->all()));
            if (! Approvals::canApprove($context->role, $pending, $settings)) {
                throw new AuthorizationException(sprintf('Approving ₱%s needs a Fleet Manager.', number_format($pending / 100, 2)));
            }

            $before = WorkOrderJournal::snapshot($locked);
            $now = CarbonImmutable::now();
            $actor = $this->journal->actor();
            $lines = $locked->lines->keyBy('id');
            $seen = [];

            foreach ($decisions as $i => $decision) {
                $line = $lines->get($decision['line_id']);
                if (! $line instanceof WorkOrderLine || isset($seen[$line->id])) {
                    throw ValidationException::withMessages(["decisions.{$i}.line_id" => 'Decide each line of this order once.']);
                }
                $seen[$line->id] = true;
                if ($line->approval_status !== LineApprovalStatus::Pending) {
                    throw new InvalidTransitionException('That line has already been decided.');
                }
                $status = LineApprovalStatus::from($decision['decision']);
                $note = isset($decision['note']) && trim((string) $decision['note']) !== '' ? trim((string) $decision['note']) : null;
                if ($status === LineApprovalStatus::Declined && $line->urgency === LineUrgency::SafetyCritical && $note === null) {
                    throw ValidationException::withMessages(["decisions.{$i}.note" => 'Declining safety-critical work needs a reason on record.']);
                }

                $line->forceFill([
                    'approval_status' => $status,
                    'approved_by' => $actor->id,
                    'approved_by_name' => $actor->name,
                    'approved_at' => $now,
                    'decline_reason' => $status === LineApprovalStatus::Declined ? ($note ?? '') : null,
                ])->save();
                $this->journal->log($locked, ApprovalAction::forDecision($status), $line->id, $line->cost(), $note, $now);
            }

            $next = Approvals::deriveOrderStatus(array_values($lines->map(fn (WorkOrderLine $l): LineApprovalStatus => $l->approval_status)->all()));
            if ($next !== WorkOrderStatus::PendingApproval) {
                $this->journal->guard($locked, $next);
                $entered = $locked->pending_approval_entered_at;
                $assign = ($next === WorkOrderStatus::Approved || $next === WorkOrderStatus::PartiallyApproved)
                    && $locked->assigned_branch_id === null && $locked->branch_id !== null;

                $locked->forceFill([
                    'status' => $next,
                    'pending_approval_entered_at' => null,
                    'approval_wait_hours' => $entered === null ? $locked->approval_wait_hours : (string) JsMath::toString(BusinessHours::between($entered, $now)),
                    'assigned_branch_id' => $assign ? WorkOrderMachine::assignOnApproval($locked->vendor, (string) $locked->branch_id)['assigned_branch_id'] : $locked->assigned_branch_id,
                ])->save();
                $this->journal->event($locked, $next, $now);
            }

            $this->journal->audit($locked, 'lines_decided', $before);

            return $locked;
        });
    }
}
