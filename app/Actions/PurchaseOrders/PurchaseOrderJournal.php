<?php

declare(strict_types=1);

namespace App\Actions\PurchaseOrders;

use App\Actions\Audit\AuditTrail;
use App\Domain\PurchaseOrders\PurchaseOrderMachine;
use App\Domain\PurchaseOrders\PurchaseOrderStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderEvent;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What every purchase-order action writes in its own transaction: the locked
 * row, the machine's verdict, the status event and the audit row.
 */
final class PurchaseOrderJournal
{
    public const array RELATIONS = ['lines.serviceTasks:id', 'lines.vehicles:id', 'events'];

    private ?User $actor = null;

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    public function lock(PurchaseOrder $order): PurchaseOrder
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Purchase orders are locked inside the action\'s transaction.');
        }

        return PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id)->load(self::RELATIONS);
    }

    public function guard(PurchaseOrder $order, PurchaseOrderStatus $to): void
    {
        $check = PurchaseOrderMachine::checkTransition($order->status, $to);
        if (! $check->ok) {
            throw new InvalidTransitionException((string) $check->reason);
        }
    }

    public function event(PurchaseOrder $order, PurchaseOrderStatus $status, CarbonImmutable $at, ?string $note = null): PurchaseOrderEvent
    {
        $event = new PurchaseOrderEvent;
        $event->forceFill([
            'purchase_order_id' => $order->id,
            'status' => $status,
            'at' => $at,
            'actor_id' => $this->actor()->id,
            'actor_name' => $this->actor()->name,
            'note' => $note,
        ])->save();

        return $event;
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    public function audit(PurchaseOrder $order, string $action, ?array $before): void
    {
        $this->audit->record($order, $action, $before, self::snapshot($order->refresh()->load(self::RELATIONS)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(PurchaseOrder $order): array
    {
        return AuditTrail::snapshot($order) + [
            'lines' => array_values($order->lines->map(fn (PurchaseOrderLine $line): array => AuditTrail::snapshot($line) + [
                'service_task_ids' => array_values($line->serviceTasks->modelKeys()),
                'vehicle_ids' => array_values($line->vehicles->modelKeys()),
            ])->all()),
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
