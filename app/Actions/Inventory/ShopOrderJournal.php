<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Audit\AuditTrail;
use App\Domain\Inventory\Decimals;
use App\Domain\Inventory\StoredOrderStatus;
use App\Models\GoodsReceiptLine;
use App\Models\ShopPurchaseOrder;
use App\Models\ShopPurchaseOrderEvent;
use App\Models\ShopPurchaseOrderLine;
use App\Models\User;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What every shop purchase-order action writes in its own transaction: the
 * locked row, the decision's event and the audit row; and what the order has
 * taken in so far.
 */
final class ShopOrderJournal
{
    public const array RELATIONS = ['lines', 'events'];

    private ?User $actor = null;

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    public function lock(ShopPurchaseOrder $order): ShopPurchaseOrder
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Purchase orders are locked inside the action\'s transaction.');
        }

        return ShopPurchaseOrder::query()->lockForUpdate()->findOrFail($order->id)->load(self::RELATIONS);
    }

    public function event(ShopPurchaseOrder $order, StoredOrderStatus $status, CarbonImmutable $at, ?string $note = null): ShopPurchaseOrderEvent
    {
        $event = new ShopPurchaseOrderEvent;
        $event->forceFill([
            'shop_purchase_order_id' => $order->id,
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
    public function audit(ShopPurchaseOrder $order, string $action, ?array $before): void
    {
        $this->audit->record($order, $action, $before, self::snapshot($order->refresh()->load(self::RELATIONS)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(ShopPurchaseOrder $order): array
    {
        return AuditTrail::snapshot($order) + [
            'lines' => array_values($order->lines->map(fn (ShopPurchaseOrderLine $line): array => AuditTrail::snapshot($line))->all()),
        ];
    }

    /**
     * What posted receipts have taken in against each line, in the purchase
     * unit. A voided receipt no longer counts.
     *
     * @param  list<string>  $lineIds
     * @return array<string, BigDecimal>
     */
    public function receivedByLine(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        $received = [];
        $rows = GoodsReceiptLine::query()
            ->whereIn('shop_purchase_order_line_id', $lineIds)
            ->whereHas('receipt', fn ($receipt) => $receipt->where('status', 'posted'))
            ->selectRaw('shop_purchase_order_line_id, sum(quantity) as received')
            ->groupBy('shop_purchase_order_line_id')
            ->toBase()
            ->get();
        foreach ($rows as $row) {
            if (is_string($row->shop_purchase_order_line_id)) {
                $received[$row->shop_purchase_order_line_id] = Decimals::of($row->received);
            }
        }

        return $received;
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
