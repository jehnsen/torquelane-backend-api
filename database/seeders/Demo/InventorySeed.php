<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Inventory\ManageStockCounts;
use App\Actions\Inventory\ProgressShopPurchaseOrder;
use App\Actions\Inventory\ReceiveGoods;
use App\Actions\Inventory\RecordOpeningStock;
use App\Actions\Inventory\SaveItem;
use App\Actions\Inventory\SaveShopPurchaseOrder;
use App\Actions\Inventory\StockLocations;
use App\Actions\Inventory\TransferStock;
use App\Models\Branch;
use App\Models\User;
use App\Models\Vendor;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use RuntimeException;

/**
 * Phase 6's demo data: the shop's own inventory, built through the same
 * Actions the API uses (as the owner), so the stock ledger, the document
 * numbers and the audit rows are the real thing, not a copy of them.
 *
 *  - a store for every branch, and a catalogue: the parts the repair shop
 *    fits (their SKUs match Actimed's fleet parts where the shop would stock
 *    the same one, so the Reorder view has a forecast to read), a consumable,
 *    a retail item, a diagnostic fee, and two detailing consumables;
 *  - opening balances, a purchase order issued and part-received (a case of
 *    10 oil filters bought for 10 each), another still a draft, a transfer
 *    from the detailing branch to the repair shop, and a posted stock count
 *    that found one litre of coolant missing.
 *
 * Ids: `item:<sku>`, `location:<branch slug>`, `shop-po:<key>`, `goods-receipt:top-up`,
 * `stock-transfer:cloths`, `stock-count:cycle`.
 */
final class InventorySeed
{
    /**
     * sku, name, type, category, uom, purchase uom, factor, price (centavos), vendor, [branch slug => [point, qty, bin, opening qty, opening cost]]
     *
     * @var list<array<string, mixed>>
     */
    private const array ITEMS = [
        ['sku' => '90915-YZZD4', 'name' => 'Engine oil filter', 'type' => 'part', 'category' => 'engine', 'uom' => 'pc', 'purchase_uom' => 'box', 'factor' => '10', 'price' => 52000, 'vendor' => 'Toyota Shaw Service Center',
            'repair' => ['point' => '8', 'qty' => '20', 'bin' => 'A-01', 'open' => '3', 'cost' => 36000]],
        ['sku' => 'HX7-5W30-1L', 'name' => 'Fully synthetic 5W-30 engine oil (litre)', 'type' => 'consumable', 'category' => 'engine', 'uom' => 'L', 'purchase_uom' => 'pail', 'factor' => '20', 'price' => 69000, 'vendor' => 'Isuzu Alabang Service',
            'repair' => ['point' => '40', 'qty' => '100', 'bin' => 'B-02', 'open' => '60', 'cost' => 46000]],
        ['sku' => '04465-0K340', 'name' => 'Front brake pad set', 'type' => 'part', 'category' => 'brakes', 'uom' => 'set', 'price' => 320000, 'vendor' => 'Toyota Shaw Service Center',
            'repair' => ['point' => '4', 'qty' => '6', 'bin' => 'A-04', 'open' => '5', 'cost' => 235000]],
        ['sku' => '17801-0L040', 'name' => 'Engine air filter element', 'type' => 'part', 'category' => 'engine', 'uom' => 'pc', 'price' => 119000, 'vendor' => 'Toyota Shaw Service Center',
            'repair' => ['point' => '6', 'qty' => '6', 'bin' => 'A-02', 'open' => '6', 'cost' => 85000]],
        ['sku' => '08889-80015', 'name' => 'Super long-life coolant (litre)', 'type' => 'consumable', 'category' => 'engine', 'uom' => 'L', 'price' => 72000, 'vendor' => 'Isuzu Alabang Service',
            'repair' => ['point' => '18', 'qty' => '40', 'bin' => 'B-03', 'open' => '25', 'cost' => 52000]],
        ['sku' => 'CAM-BLT-A', 'name' => 'Camber adjustment bolt', 'type' => 'part', 'category' => 'tires', 'uom' => 'pc', 'price' => 45000, 'vendor' => 'Bridgestone Tire Center',
            'repair' => ['point' => '6', 'qty' => '12', 'bin' => 'C-01']],
        ['sku' => 'RAG-SHOP', 'name' => 'Shop rags (bag)', 'type' => 'consumable', 'category' => 'shop', 'uom' => 'bag', 'price' => 0, 'vendor' => null,
            'repair' => ['point' => '4', 'qty' => '10', 'bin' => 'D-01', 'open' => '10', 'cost' => 12000]],
        ['sku' => 'WPR-BLD-22', 'name' => 'Wiper blade (pair)', 'type' => 'retail', 'category' => 'safety', 'uom' => 'pair', 'price' => 82000, 'vendor' => 'Bridgestone Tire Center',
            'repair' => ['point' => '3', 'qty' => '8', 'bin' => 'R-01', 'open' => '8', 'cost' => 60000]],
        ['sku' => 'SVC-DIAG', 'name' => 'Diagnostic scan fee', 'type' => 'service_fee', 'category' => 'service', 'uom' => 'service', 'price' => 150000, 'vendor' => null],
        ['sku' => 'DTL-SHAMPOO-5L', 'name' => 'pH-neutral car shampoo (5 L)', 'type' => 'consumable', 'category' => 'detailing', 'uom' => 'jug', 'price' => 0, 'vendor' => null,
            'detailing' => ['point' => '2', 'qty' => '6', 'bin' => 'S-01', 'open' => '4', 'cost' => 85000]],
        ['sku' => 'DTL-MF-CLOTH', 'name' => 'Microfibre cloth', 'type' => 'consumable', 'category' => 'detailing', 'uom' => 'pc', 'price' => 0, 'vendor' => null,
            'detailing' => ['point' => '10', 'qty' => '40', 'bin' => 'S-02', 'open' => '30', 'cost' => 4500]],
    ];

    /**
     * @param  array<string, string>  $ids  source id → ULID, extended in place
     */
    public static function run(string $organizationId, array &$ids): void
    {
        $owner = User::query()->findOrFail($ids['owner@mekanikomore.ph'] ?? throw new RuntimeException('InventorySeed needs the demo owner.'));
        $context = app(TenantContextResolver::class)->resolve($owner, null)->context ?? throw new RuntimeException('The demo owner has no tenant context.');

        app(TenantManager::class)->actingAs($context, function () use (&$ids): void {
            self::seed($ids);
        });
    }

    /**
     * @param  array<string, string>  $ids
     */
    private static function seed(array &$ids): void
    {
        $locations = app(StockLocations::class);
        $branches = ['repair' => $ids['mekanikomor-binan'], 'detailing' => $ids['samahuzai-binan']];
        $stores = [];
        foreach (Branch::query()->orderBy('id')->get() as $branch) {
            $store = $locations->storeOf($branch->id);
            $stores[$branch->id] = $store;
            $ids['location:'.$branch->slug] = $store->id;
        }

        $vendors = Vendor::query()->get()->keyBy('name');
        $vendorId = fn (string $name): string => ($vendors->get($name) ?? throw new RuntimeException("Seed vendor [{$name}] is missing."))->id;
        $save = app(SaveItem::class);
        $opening = [];
        foreach (self::ITEMS as $row) {
            $item = $save->create(array_filter([
                'sku' => $row['sku'],
                'name' => $row['name'],
                'item_type' => $row['type'],
                'category' => $row['category'],
                'uom' => $row['uom'],
                'purchase_uom' => $row['purchase_uom'] ?? null,
                'purchase_uom_factor' => $row['factor'] ?? null,
                'default_price_cents' => $row['price'],
                'preferred_vendor_id' => is_string($row['vendor'] ?? null) ? $vendorId($row['vendor']) : null,
            ], fn (mixed $v): bool => $v !== null));
            $ids['item:'.$row['sku']] = $item->id;

            foreach (['repair', 'detailing'] as $key) {
                if (! isset($row[$key])) {
                    continue;
                }
                $at = $row[$key];
                $save->setBranchSettings($item, $branches[$key], ['reorder_point' => $at['point'], 'reorder_qty' => $at['qty'], 'bin' => $at['bin']]);
                if (isset($at['open'])) {
                    $opening[$key][] = ['item_id' => $item->id, 'quantity' => $at['open'], 'unit_cost_cents' => $at['cost']];
                }
            }
        }

        $record = app(RecordOpeningStock::class);
        foreach ($opening as $key => $lines) {
            $record->handle($stores[$branches[$key]], $lines, 'Opening balance');
        }

        // A purchase order for the repair shop, issued and part-received: 3 boxes
        // of 10 oil filters (₱3,500 a box) and 4 brake pad sets (₱2,350 each);
        // one box and all four sets have come in.
        $orders = app(SaveShopPurchaseOrder::class);
        $order = $orders->create([
            'branch_id' => $branches['repair'],
            'vendor_id' => $vendorId('Toyota Shaw Service Center'),
            'notes' => 'Monthly top-up',
            'lines' => [
                ['item_id' => $ids['item:90915-YZZD4'], 'quantity' => '3', 'unit_cost_cents' => 350000],
                ['item_id' => $ids['item:04465-0K340'], 'quantity' => '4', 'unit_cost_cents' => 235000],
            ],
        ]);
        $ids['shop-po:top-up'] = $order->id;
        app(ProgressShopPurchaseOrder::class)->issue($order);
        $order->load('lines');
        $filters = $order->lines->firstOrFail();
        $pads = $order->lines->skip(1)->firstOrFail();
        $receipt = app(ReceiveGoods::class)->receive($order, ['supplier_ref' => 'DR-48213', 'lines' => [
            ['shop_purchase_order_line_id' => $filters->id, 'quantity' => '1'],
            ['shop_purchase_order_line_id' => $pads->id, 'quantity' => '4'],
        ]]);
        $ids['goods-receipt:top-up'] = $receipt->id;

        $draft = $orders->create([
            'branch_id' => $branches['repair'],
            'vendor_id' => $vendorId('Isuzu Alabang Service'),
            'lines' => [['item_id' => $ids['item:08889-80015'], 'quantity' => '2', 'unit_cost_cents' => 104000]],
        ]);
        $ids['shop-po:coolant-draft'] = $draft->id;

        // Ten microfibre cloths from the detailing branch to the repair shop.
        $transfer = app(TransferStock::class)->handle($stores[$branches['detailing']], $stores[$branches['repair']], [['item_id' => $ids['item:DTL-MF-CLOTH'], 'quantity' => '10']], 'Cloths for the repair bays');
        $ids['stock-transfer:cloths'] = $transfer->id;

        // A cycle count at the repair store: a litre of coolant short.
        $counts = app(ManageStockCounts::class);
        $store = $stores[$branches['repair']];
        $count = $counts->open($store, ['item_ids' => [$ids['item:08889-80015'], $ids['item:RAG-SHOP']], 'reason' => 'Cycle count']);
        $counts->enter($count, [
            ['item_id' => $ids['item:08889-80015'], 'counted_quantity' => '24', 'reason' => 'Spillage'],
            ['item_id' => $ids['item:RAG-SHOP'], 'counted_quantity' => '10'],
        ]);
        $counts->post($count);
        $ids['stock-count:cycle'] = $count->id;
    }
}
