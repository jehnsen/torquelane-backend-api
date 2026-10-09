<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\WorkOrders\WorkOrderView;
use App\Domain\Access\Capability;
use App\Domain\Approvals\Approvals;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Billing\Billing;
use App\Domain\Billing\BillingTotals;
use App\Domain\Shared\BusinessHours;
use App\Domain\Shared\Num;
use App\Domain\WorkOrders\WorkOrderMachine;
use App\Domain\WorkOrders\WorkOrderReference;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Models\ApprovalLogEntry;
use App\Models\WorkOrderEvent;
use App\Models\WorkOrderLine;
use App\Models\WorkOrderPart;
use App\Models\WorkOrderTask;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A work order with its lines, history and approval log. Money in centavos.
 * `totals` bills every line; `approved_totals` only the approved ones (what
 * the customer authorised). Who did what is shown by name; the shop's
 * internal ids (branch, bay, technician, staff users) only to staff.
 *
 * @property WorkOrderView $resource
 */
final class WorkOrderResource extends JsonResource
{
    public function __construct(WorkOrderView $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $order = $view->order;
        $settings = $view->settings;
        $context = app(TenantManager::class)->context();
        $staff = $context?->isStaff() ?? false;
        $billable = array_values($order->lines->map(fn (WorkOrderLine $l) => $l->billable())->all());
        $staffOnly = fn (?string $id): ?string => $staff ? $id : null;
        $waiting = $order->status === WorkOrderStatus::PendingApproval && $order->pending_approval_entered_at !== null
            ? BusinessHours::between($order->pending_approval_entered_at, CarbonImmutable::now())
            : null;

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'display_reference' => WorkOrderReference::display($order->reference),
            'title' => $order->title,
            'type' => $order->type,
            'status' => $order->status->value,
            'lifecycle_stage' => WorkOrderMachine::lifecycleStage($order->status, $order->collected_at !== null)->value,
            'next_statuses' => array_map(fn ($s): string => $s->value, WorkOrderMachine::nextStatuses($order->status)),
            'priority' => $order->priority,
            'customer_account_id' => $order->customer_account_id,
            'vehicle_id' => $order->vehicle_id,
            'branch_id' => $staffOnly($order->branch_id),
            'assigned_branch_id' => $staffOnly($order->assigned_branch_id),
            'bay_id' => $staffOnly($order->bay_id),
            'technician_id' => $staffOnly($order->technician_id),
            'technician_name' => $order->technician_name,
            'vendor' => $order->vendor,
            'in_house' => WorkOrderMachine::isInHouse($order->vendor),
            'opened_on' => $order->opened_on->toDateString(),
            'scheduled_for' => $order->scheduled_for?->toDateString(),
            'scheduled_time' => $order->scheduled_time,
            'odometer_at_intake' => $order->odometer_at_intake === null ? null : (string) $order->odometer_at_intake,
            'odometer_at_service' => $order->odometer_at_service === null ? null : (string) $order->odometer_at_service,
            'findings' => $order->findings,
            'notes' => $order->notes,
            'cancellation_reason' => $order->cancellation_reason,
            'labor_cost_cents' => $order->labor_cost_cents,
            'parts_cost_cents' => $order->parts_cost_cents,
            'totals' => self::totals(Billing::totals($billable, $settings->vatRatePct, $settings->miscFeeFlatCents)),
            'approved_totals' => self::totals(Billing::totals($billable, $settings->vatRatePct, $settings->miscFeeFlatCents, [LineApprovalStatus::Approved])),
            'approval' => [
                'pending_value_cents' => Approvals::pendingValue($billable),
                'approved_value_cents' => Approvals::approvedValue($billable),
                'declined_value_cents' => Approvals::declinedValue($billable),
                'required_approver' => Approvals::requiredApprover(Approvals::pendingValue($billable), $settings)->value,
                'pending_approval_entered_at' => $order->pending_approval_entered_at?->toIso8601ZuluString(),
                'approval_wait_hours' => $order->approval_wait_hours === null ? null : Num::of($order->approval_wait_hours),
                'sla_hours' => $settings->slaHours,
                // While pending: business hours waited so far, and whether that is past the SLA.
                'waiting_hours' => $waiting,
                'sla_breached' => $waiting !== null && $waiting > $settings->slaHours,
                // Whether the caller may decide the pending lines: the capability and
                // authority over the order's pending value (DecideLines checks both again).
                'can_approve' => $context !== null
                    && $context->can(Capability::WorkOrderApprove)
                    && Approvals::canApprove($context->role, Approvals::pendingValue($billable), $settings),
            ],
            'lines' => array_values($order->lines->map(fn (WorkOrderLine $line): array => [
                'id' => $line->id,
                'position' => $line->position,
                'service_task_id' => $line->service_task_id,
                'description' => $line->description,
                'category' => $line->category,
                'quantity' => (string) $line->quantity,
                'unit_part_rate_cents' => $line->unit_part_rate_cents,
                'part_cost_cents' => $line->part_cost_cents,
                'labour_hours' => (string) $line->labour_hours,
                'labour_rate_cents' => $line->labour_rate_cents,
                'labour_cost_cents' => $line->labour_cost_cents,
                'line_cost_cents' => $line->cost(),
                'urgency' => $line->urgency->value,
                'parts_source' => $line->parts_source->value,
                'approval_status' => $line->approval_status->value,
                'approved_by_name' => $line->approved_by_name,
                'approved_at' => $line->approved_at?->toIso8601ZuluString(),
                'decline_reason' => $line->decline_reason,
                'photos' => $line->photos,
            ])->all()),
            'task_ids' => array_values($order->tasks->map(fn (WorkOrderTask $t): string => $t->service_task_id)->all()),
            'parts' => array_values($order->parts->map(fn (WorkOrderPart $p): array => [
                'id' => $p->id,
                'part_number' => $p->part_number,
                'name' => $p->name,
                'quantity' => (string) $p->quantity,
                'unit_cost_cents' => $p->unit_cost_cents,
            ])->all()),
            'history' => array_values($order->events->map(fn (WorkOrderEvent $e): array => [
                'id' => $e->id,
                'status' => $e->status->value,
                'at' => $e->at->toIso8601ZuluString(),
                'actor_name' => $e->actor_name,
            ])->all()),
            'approval_log' => array_values($order->approvalLog->map(fn (ApprovalLogEntry $e): array => [
                'id' => $e->id,
                'line_id' => $e->line_id,
                'action' => $e->action->value,
                'actor_name' => $e->actor_name,
                'at' => $e->at->toIso8601ZuluString(),
                'note' => $e->note,
                'amount_at_time_cents' => $e->amount_at_time_cents,
            ])->all()),
            'completed_on' => $order->completed_on?->toDateString(),
            'collected_at' => $order->collected_at?->toIso8601ZuluString(),
            'created_at' => $order->created_at->toIso8601ZuluString(),
            'updated_at' => $order->updated_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    private static function totals(BillingTotals $t): array
    {
        return [
            'parts_total_cents' => $t->partsTotalCents,
            'labour_total_cents' => $t->labourTotalCents,
            'sub_total_cents' => $t->subTotalCents,
            'misc_total_cents' => $t->miscTotalCents,
            'tax_total_cents' => $t->taxTotalCents,
            'vat_rate_pct' => $t->vatRatePct,
            'grand_total_cents' => $t->grandTotalCents,
        ];
    }
}
