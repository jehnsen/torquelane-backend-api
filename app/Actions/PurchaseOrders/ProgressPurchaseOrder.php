<?php

declare(strict_types=1);

namespace App\Actions\PurchaseOrders;

use App\Actions\Audit\AuditTrail;
use App\Actions\WorkOrders\ApprovalSettingsResolver;
use App\Domain\PurchaseOrders\PurchaseOrderExport;
use App\Domain\PurchaseOrders\PurchaseOrders;
use App\Domain\PurchaseOrders\PurchaseOrderStatus;
use App\Models\FleetPart;
use App\Models\PurchaseOrder;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A purchase order's moves after drafting (../web `updatePurchaseOrderStatus`),
 * each one transaction on the locked order with its event and audit row:
 *
 *  - send (draft → sent): issuing it is the APPROVAL of its spend, held to the
 *    issuer's band on the order's total under the account's settings;
 *  - receive (sent → received): the lines' quantities go back into the
 *    account's own stock (rows locked), so the next forecast sees the
 *    shortfall close — stock is per account, never another's shelf;
 *  - cancel (draft | sent → cancelled), with a reason.
 */
final class ProgressPurchaseOrder
{
    public function __construct(
        private readonly PurchaseOrderJournal $journal,
        private readonly ApprovalSettingsResolver $settings,
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    public function send(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = $this->journal->lock($order);
            $this->journal->guard($locked, PurchaseOrderStatus::Sent);

            $settings = $this->settings->forAccount($locked->customerAccount()->firstOrFail(), null);
            if (! PurchaseOrders::canIssue($this->tenancy->require()->role, $locked->total_cents, $settings)) {
                throw new AuthorizationException(sprintf('Issuing ₱%s is above your approval limit; it needs a Fleet Manager.', PurchaseOrderExport::pesos($locked->total_cents)));
            }

            $before = PurchaseOrderJournal::snapshot($locked);
            $now = CarbonImmutable::now();
            $locked->forceFill(['status' => PurchaseOrderStatus::Sent, 'sent_at' => $now, 'sent_by_name' => $this->journal->actor()->name])->save();
            $this->journal->event($locked, PurchaseOrderStatus::Sent, $now);
            $this->journal->audit($locked, 'sent', $before);

            return $locked;
        });
    }

    public function receive(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = $this->journal->lock($order);
            $this->journal->guard($locked, PurchaseOrderStatus::Received);

            $received = [];
            foreach ($locked->lines as $line) {
                if ($line->fleet_part_id !== null) {
                    $received[$line->fleet_part_id] = ($received[$line->fleet_part_id] ?? 0) + $line->quantity;
                }
            }
            $parts = FleetPart::query()->where('customer_account_id', $locked->customer_account_id)->whereIn('id', array_keys($received))->orderBy('id')->lockForUpdate()->get();
            foreach ($parts as $part) {
                $before = AuditTrail::snapshot($part);
                $part->forceFill(['current_stock' => $part->current_stock + $received[$part->id]])->save();
                $this->audit->record($part, 'restocked', $before, AuditTrail::snapshot($part));
            }

            $before = PurchaseOrderJournal::snapshot($locked);
            $now = CarbonImmutable::now();
            $locked->forceFill(['status' => PurchaseOrderStatus::Received, 'received_at' => $now, 'received_by_name' => $this->journal->actor()->name])->save();
            $this->journal->event($locked, PurchaseOrderStatus::Received, $now);
            $this->journal->audit($locked, 'received', $before);

            return $locked;
        });
    }

    public function cancel(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason): PurchaseOrder {
            $locked = $this->journal->lock($order);
            $this->journal->guard($locked, PurchaseOrderStatus::Cancelled);

            $before = PurchaseOrderJournal::snapshot($locked);
            $now = CarbonImmutable::now();
            $locked->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => $now,
                'cancelled_by_name' => $this->journal->actor()->name,
                'cancellation_reason' => $reason,
            ])->save();
            $this->journal->event($locked, PurchaseOrderStatus::Cancelled, $now, $reason);
            $this->journal->audit($locked, 'cancelled', $before);

            return $locked;
        });
    }

    /**
     * Whether the caller may issue this order (draft, within their band): what
     * the frontend shows on the "Mark as sent" control.
     */
    public function canSend(PurchaseOrder $order): bool
    {
        $context = $this->tenancy->require();

        return $order->status === PurchaseOrderStatus::Draft
            && PurchaseOrders::canIssue($context->role, $order->total_cents, $this->settings->forAccount($order->customerAccount()->firstOrFail(), null));
    }
}
