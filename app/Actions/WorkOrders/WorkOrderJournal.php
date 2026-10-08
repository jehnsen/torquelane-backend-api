<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Actions\Audit\AuditTrail;
use App\Domain\Approvals\ApprovalAction;
use App\Domain\WorkOrders\WorkOrderMachine;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\ApprovalLogEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What every work-order action shares: the row lock, the machine's gate, and
 * the append-only records (status events, approval log) plus the audit row —
 * all written inside the caller's one transaction.
 */
final class WorkOrderJournal
{
    public const string SYSTEM_ACTOR = 'System (auto-approval)';

    private ?User $actor = null;

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /** Re-reads the order FOR UPDATE: every decision is made on the locked row. */
    public function lock(WorkOrder $order): WorkOrder
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Work orders are locked inside the action\'s transaction.');
        }

        return WorkOrder::query()->lockForUpdate()->findOrFail($order->id)->load(WorkOrderQueries::RELATIONS);
    }

    /** The machine is the only gate: refuse with its reason. */
    public function guard(WorkOrder $order, WorkOrderStatus $to): void
    {
        $check = WorkOrderMachine::checkTransition($order->status, $order->lines->count(), $to);
        if (! $check->ok) {
            throw new InvalidTransitionException((string) $check->reason);
        }
    }

    public function event(WorkOrder $order, WorkOrderStatus $status, CarbonImmutable $at, bool $system = false): WorkOrderEvent
    {
        $event = new WorkOrderEvent;
        $event->forceFill([
            'work_order_id' => $order->id,
            'status' => $status,
            'at' => $at,
            'actor_id' => $system ? null : $this->actor()->id,
            'actor_name' => $system ? self::SYSTEM_ACTOR : $this->actor()->name,
        ])->save();

        return $event;
    }

    public function log(WorkOrder $order, ApprovalAction $action, ?string $lineId, int $amountCents, ?string $note, CarbonImmutable $at, bool $system = false): ApprovalLogEntry
    {
        $entry = new ApprovalLogEntry;
        $entry->forceFill([
            'work_order_id' => $order->id,
            'line_id' => $lineId,
            'action' => $action,
            'actor_id' => $system ? null : $this->actor()->id,
            'actor_name' => $system ? self::SYSTEM_ACTOR : $this->actor()->name,
            'at' => $at,
            'note' => $note,
            'amount_at_time_cents' => $amountCents,
        ])->save();

        return $entry;
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    public function audit(WorkOrder $order, string $action, ?array $before): void
    {
        $this->audit->record($order, $action, $before, self::snapshot($order->refresh()->load(WorkOrderQueries::RELATIONS)));
    }

    /**
     * The order with its lines, tasks and parts; the append-only records are
     * left out (they are their own record).
     *
     * @return array<string, mixed>
     */
    public static function snapshot(WorkOrder $order): array
    {
        return AuditTrail::snapshot($order) + [
            'lines' => array_values($order->lines->map(fn ($line): array => AuditTrail::snapshot($line))->all()),
            'task_ids' => array_values($order->tasks->pluck('service_task_id')->all()),
            'parts' => array_values($order->parts->map(fn ($part): array => AuditTrail::snapshot($part))->all()),
        ];
    }

    public function actor(): User
    {
        $userId = $this->tenancy->require()->userId;
        if ($this->actor === null || $this->actor->id !== $userId) {
            $this->actor = User::query()->findOrFail($userId);
        }

        return $this->actor;
    }
}
