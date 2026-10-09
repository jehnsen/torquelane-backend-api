<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Billing\Billing;
use App\Domain\Inventory\StoredOrderStatus;
use App\Domain\Numbering\DocumentType;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\PartsSource;
use App\Exceptions\InvalidTransitionException;
use App\Models\Item;
use App\Models\ShopPurchaseOrder;
use App\Models\ShopPurchaseOrderLine;
use App\Models\Vendor;
use App\Models\WorkOrderLine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The shop's own purchase orders, drafted. The number comes from the
 * organization's `shop_purchase_order` series (`SPO-2026-0001`) in the same transaction (R8), at
 * creation like Phase 4's. Lines are in the purchase unit; every total is
 * the server's: a line is quantity × unit cost rounded once to a centavo
 * (and stored, CHECKed), the order the sum of its stored lines.
 *
 * A line buys a stocked item, or is bought FOR A JOB: it then names the
 * work-order line (parts_source = purchased_for_job) it serves and needs no
 * item, since it never goes on the shelf.
 */
final class SaveShopPurchaseOrder
{
    public function __construct(
        private readonly ShopOrderJournal $journal,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param  array{branch_id: string, vendor_id: string, notes?: string, expected_on?: string|null, lines: list<array<string, mixed>>}  $data  validated
     */
    public function create(array $data): ShopPurchaseOrder
    {
        return DB::transaction(function () use ($data): ShopPurchaseOrder {
            $vendor = Vendor::query()->find($data['vendor_id']);
            if (! $vendor instanceof Vendor) {
                throw ValidationException::withMessages(['vendor_id' => 'That vendor is not on your list.']);
            }
            if (! $vendor->is_active) {
                throw ValidationException::withMessages(['vendor_id' => "{$vendor->name} is inactive."]);
            }
            $now = CarbonImmutable::now();
            $actor = $this->journal->actor();

            $order = new ShopPurchaseOrder;
            $order->forceFill([
                'branch_id' => $data['branch_id'],
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'reference' => $this->numbers->issue($vendor->organization_id, null, DocumentType::ShopPurchaseOrder, $now)->formatted,
                'created_on' => Calendar::toDate($now),
                'expected_on' => $data['expected_on'] ?? null,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
                'notes' => $data['notes'] ?? '',
            ])->save();

            $this->writeLines($order, $data['lines']);
            $this->journal->event($order, StoredOrderStatus::Draft, $now);
            $this->journal->audit($order, 'created', null);

            return $order;
        });
    }

    /**
     * Edit a draft: its notes, expected date, vendor and (replacing them all) its lines.
     *
     * @param  array{vendor_id?: string, notes?: string, expected_on?: string|null, lines?: list<array<string, mixed>>}  $data  validated
     */
    public function update(ShopPurchaseOrder $order, array $data): ShopPurchaseOrder
    {
        return DB::transaction(function () use ($order, $data): ShopPurchaseOrder {
            $locked = $this->journal->lock($order);
            if ($locked->status !== StoredOrderStatus::Draft) {
                throw new InvalidTransitionException("Purchase order {$locked->reference} has been issued; it can no longer be edited.");
            }
            $before = ShopOrderJournal::snapshot($locked);

            if (isset($data['vendor_id']) && $data['vendor_id'] !== $locked->vendor_id) {
                $vendor = Vendor::query()->find($data['vendor_id']);
                if (! $vendor instanceof Vendor || ! $vendor->is_active) {
                    throw ValidationException::withMessages(['vendor_id' => 'That vendor is not on your list, or is inactive.']);
                }
                $locked->forceFill(['vendor_id' => $vendor->id, 'vendor_name' => $vendor->name]);
            }
            if (array_key_exists('notes', $data)) {
                $locked->forceFill(['notes' => $data['notes']]);
            }
            if (array_key_exists('expected_on', $data)) {
                $locked->forceFill(['expected_on' => $data['expected_on']]);
            }
            $locked->save();

            if (isset($data['lines'])) {
                ShopPurchaseOrderLine::query()->where('shop_purchase_order_id', $locked->id)->delete();
                $this->writeLines($locked, $data['lines']);
            }
            $this->journal->audit($locked, 'draft_updated', $before);

            return $locked;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(ShopPurchaseOrder $order, array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A purchase order needs at least one line.']);
        }

        $total = 0;
        foreach ($lines as $position => $input) {
            $itemId = isset($input['item_id']) && is_string($input['item_id']) ? $input['item_id'] : null;
            $jobLineId = isset($input['work_order_line_id']) && is_string($input['work_order_line_id']) ? $input['work_order_line_id'] : null;
            $field = "lines.{$position}";

            $item = null;
            if ($itemId !== null) {
                $item = Item::query()->find($itemId);
                if (! $item instanceof Item) {
                    throw ValidationException::withMessages(["{$field}.item_id" => 'That item does not exist.']);
                }
                if (! $item->is_active || ! $item->is_stocked) {
                    throw ValidationException::withMessages(["{$field}.item_id" => "{$item->name} is not an active stocked item."]);
                }
            }
            if ($item === null && $jobLineId === null) {
                throw ValidationException::withMessages(["{$field}.item_id" => 'Name the item to stock, or the job line this is bought for.']);
            }
            if ($jobLineId !== null) {
                $jobLine = WorkOrderLine::query()->find($jobLineId);
                if (! $jobLine instanceof WorkOrderLine || $jobLine->parts_source !== PartsSource::PurchasedForJob) {
                    throw ValidationException::withMessages(["{$field}.work_order_line_id" => 'That job line is not marked "purchased for job".']);
                }
            }

            $quantity = BigDecimal::of(is_scalar($input['quantity'] ?? null) ? (string) $input['quantity'] : '0');
            $unitCost = is_int($input['unit_cost_cents'] ?? null) ? $input['unit_cost_cents'] : 0;
            $lineTotal = Billing::roundCents($quantity->multipliedBy($unitCost));
            $description = isset($input['description']) && is_string($input['description']) && trim($input['description']) !== ''
                ? trim($input['description'])
                : ($item->name ?? '');
            if ($description === '') {
                throw ValidationException::withMessages(["{$field}.description" => 'Describe what is being bought.']);
            }

            $line = new ShopPurchaseOrderLine;
            $line->forceFill([
                'shop_purchase_order_id' => $order->id,
                'position' => $position,
                'item_id' => $item?->id,
                'description' => $description,
                'quantity' => $quantity,
                'unit_cost_cents' => $unitCost,
                'line_total_cents' => $lineTotal,
                'work_order_line_id' => $jobLineId,
            ])->save();
            $total += $lineTotal;
        }

        $order->forceFill(['total_cents' => $total])->save();
    }
}
