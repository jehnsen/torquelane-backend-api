<?php

declare(strict_types=1);

namespace App\Actions\PurchaseOrders;

use App\Actions\Numbering\DocumentNumbers;
use App\Actions\Parts\PartsQueries;
use App\Domain\Numbering\DocumentType;
use App\Domain\PurchaseOrders\PurchaseOrders;
use App\Domain\PurchaseOrders\PurchaseOrderStatus;
use App\Domain\Shared\Calendar;
use App\Models\CustomerAccount;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Forecast rows → draft purchase orders, one per preferred vendor (../web
 * store.ts `generatePurchaseOrders`). The caller names the PARTS; the server
 * recomputes the forecast itself, on the locked account, and orders each
 * chosen part's shortfall at its unit cost — quantities and prices a client
 * sends are never read. Each order is numbered from the organization's
 * purchase_order series in this transaction.
 */
final class RaisePurchaseOrders
{
    public function __construct(
        private readonly PartsQueries $parts,
        private readonly PurchaseOrderJournal $journal,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param  list<string>  $partIds
     * @return list<PurchaseOrder>
     */
    public function handle(CustomerAccount $account, int $horizonWeeks, array $partIds, string $notes): array
    {
        return DB::transaction(function () use ($account, $horizonWeeks, $partIds, $notes): array {
            // One generation per account at a time: the forecast reads open orders.
            $locked = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $drafts = PurchaseOrders::fromDemand($this->parts->forecast($locked, $horizonWeeks), $partIds);
            if ($drafts === []) {
                throw ValidationException::withMessages(['part_ids' => 'None of these parts has a shortfall left to order over this horizon.']);
            }

            $now = CarbonImmutable::now();
            $actor = $this->journal->actor();
            $created = [];
            foreach ($drafts as $draft) {
                $order = new PurchaseOrder;
                $order->forceFill([
                    'customer_account_id' => $locked->id,
                    'reference' => $this->numbers->issue($locked->organization_id, null, DocumentType::PurchaseOrder, $now)->formatted,
                    'vendor' => $draft->vendor,
                    'status' => PurchaseOrderStatus::Draft,
                    'created_on' => Calendar::toDate($now),
                    'created_by' => $actor->id,
                    'created_by_name' => $actor->name,
                    'notes' => $notes,
                    'total_cents' => array_sum(array_map(fn ($line): int => $line->totalCents(), $draft->lines)),
                ])->save();

                foreach ($draft->lines as $position => $draftLine) {
                    $line = new PurchaseOrderLine;
                    $line->forceFill([
                        'purchase_order_id' => $order->id,
                        'customer_account_id' => $locked->id,
                        'fleet_part_id' => $draftLine->partId,
                        'position' => $position,
                        'description' => $draftLine->description,
                        'quantity' => $draftLine->quantity,
                        'unit_cost_cents' => $draftLine->unitCostCents,
                        'line_total_cents' => $draftLine->totalCents(),
                    ])->save();
                    $line->serviceTasks()->attach(array_fill_keys($draftLine->serviceTaskIds, ['organization_id' => $locked->organization_id]));
                    $line->vehicles()->attach(array_fill_keys($draftLine->vehicleIds, ['organization_id' => $locked->organization_id]));
                }

                $this->journal->event($order, PurchaseOrderStatus::Draft, $now);
                $this->journal->audit($order, 'created', null);
                $created[] = $order;
            }

            return $created;
        });
    }
}
