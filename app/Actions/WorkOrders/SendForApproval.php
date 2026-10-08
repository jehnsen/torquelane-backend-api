<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Approvals\ApprovalAction;
use App\Domain\Approvals\Approvals;
use App\Domain\Approvals\ApproverBand;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Numbering\DocumentType;
use App\Domain\WorkOrders\WorkOrderMachine;
use App\Domain\WorkOrders\WorkOrderReference;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * draft → pending_approval: the quotation goes to the customer. This is where
 * the order is numbered (from the organization's work_order series, in this
 * transaction — a rollback returns the number).
 *
 * Inside the auto-approve band (strictly under the account's ceiling) the
 * system approves every line itself and the order opens straight to
 * `approved`, as ../web's creation did; it is still numbered, since it has
 * left draft.
 */
final class SendForApproval
{
    public function __construct(
        private readonly WorkOrderJournal $journal,
        private readonly ApprovalSettingsResolver $settings,
        private readonly WorkOrderBranch $branches,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function handle(WorkOrder $order): WorkOrder
    {
        return DB::transaction(function () use ($order): WorkOrder {
            $locked = $this->journal->lock($order);
            if ($locked->status === WorkOrderStatus::PendingApproval) {
                throw new InvalidTransitionException('This quotation is already with the customer.');
            }
            $this->journal->guard($locked, WorkOrderStatus::PendingApproval);
            $before = WorkOrderJournal::snapshot($locked);
            $now = CarbonImmutable::now();

            $branchId = $this->branches->takeIn($locked->branch_id);
            $settings = $this->settings->forAccount($locked->customerAccount()->firstOrFail(), $branchId);

            $lines = $locked->lines;
            $pending = Approvals::pendingValue(array_values($lines->map(fn (WorkOrderLine $l) => $l->billable()->withStatus(LineApprovalStatus::Pending))->all()));
            $auto = Approvals::requiredApprover($pending, $settings) === ApproverBand::Auto;

            $reference = WorkOrderReference::has($locked->reference)
                ? $locked->reference
                : $this->numbers->issue($locked->organization_id, null, DocumentType::WorkOrder, $now)->formatted;

            foreach ($lines as $line) {
                $line->forceFill($auto
                    ? ['approval_status' => LineApprovalStatus::Approved, 'approved_by' => null, 'approved_by_name' => WorkOrderJournal::SYSTEM_ACTOR, 'approved_at' => $now, 'decline_reason' => null]
                    : ['approval_status' => LineApprovalStatus::Pending, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'decline_reason' => null])->save();
            }

            if ($auto) {
                $locked->forceFill([
                    'reference' => $reference,
                    'branch_id' => $branchId,
                    'status' => WorkOrderStatus::Approved,
                    'pending_approval_entered_at' => null,
                    'approval_wait_hours' => null,
                    'assigned_branch_id' => $locked->assigned_branch_id ?? ($branchId === null ? null : WorkOrderMachine::assignOnApproval($locked->vendor, $branchId)['assigned_branch_id']),
                ])->save();
                foreach ($lines as $line) {
                    $this->journal->log($locked, ApprovalAction::AutoApproved, $line->id, $line->cost(), null, $now, system: true);
                }
                $this->journal->event($locked, WorkOrderStatus::Approved, $now, system: true);
            } else {
                $locked->forceFill([
                    'reference' => $reference,
                    'branch_id' => $branchId,
                    'status' => WorkOrderStatus::PendingApproval,
                    'pending_approval_entered_at' => $now,
                    'approval_wait_hours' => null,
                ])->save();
                $count = $lines->count();
                $this->journal->log($locked, ApprovalAction::SentForApproval, null, $pending, sprintf('Quotation sent for %d %s.', $count, $count === 1 ? 'line' : 'lines'), $now);
                $this->journal->event($locked, WorkOrderStatus::PendingApproval, $now);
            }

            $this->journal->audit($locked, $auto ? 'auto_approved' : 'sent_for_approval', $before);

            return $locked;
        });
    }
}
