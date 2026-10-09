<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\ShopPurchaseOrderLine;
use App\Models\StockMove;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The shop's own purchase orders and the goods receipts against them.
 * Seed: SPO-2026-0001 to Toyota Shaw Service Center, issued — 3 boxes of 10
 * oil filters (₱3,500 a box) and 4 brake pad sets (₱2,350) — with one box
 * and all four sets received on GR-2026-0001; SPO-2026-0002 is a coolant draft.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->repair = $this->world->id('mekanikomor-binan');
});

function rcSignIn(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

function vendorId(string $name): string
{
    return asSystem(fn () => Vendor::query()->where('name', $name)->firstOrFail()->id);
}

/** @return array{on_hand: string, avg_cost_cents: int} */
function onHandOf(string $sku, string $branchId): array
{
    $item = collect(test()->getJson('/api/v1/items/'.test()->world->id("item:{$sku}"))->json('data.branches'))->firstWhere('branch_id', $branchId);

    return ['on_hand' => $item['on_hand'], 'avg_cost_cents' => $item['avg_cost_cents']];
}

it('raises a draft numbered from its own series, priced on the server', function () {
    rcSignIn('owner@mekanikomore.ph');

    $response = $this->postJson('/api/v1/shop-purchase-orders', [
        'branch_id' => $this->repair,
        'vendor_id' => vendorId('Isuzu Alabang Service'),
        'notes' => 'Coolant for the weekend',
        'expected_on' => '2026-10-12',
        'lines' => [['item_id' => $this->world->id('item:08889-80015'), 'quantity' => '2.5', 'unit_cost_cents' => 104001, 'line_total_cents' => 1]],
    ])->assertCreated();

    $response
        ->assertJsonPath('data.reference', 'SPO-2026-0003')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.vendor_name', 'Isuzu Alabang Service')
        ->assertJsonPath('data.created_by_name', 'Mike Manabat')
        // 2.5 × ₱1,040.01 = ₱2,600.025 → rounded once, to ₱2,600.03; the client's own total is never read.
        ->assertJsonPath('data.lines.0.line_total_cents', 260003)
        ->assertJsonPath('data.total_cents', 260003)
        ->assertJsonPath('data.can_issue', true)
        ->assertJsonPath('data.can_receive', false)
        ->assertJsonPath('data.history.0.status', 'draft');

    // Phase 4's customer purchase orders are a different series, untouched.
    expect(asSystem(fn () => AuditLog::query()->where('organization_id', $this->world->id('prov-mekanikomore'))->where('entity_type', 'shop_purchase_order')->where('action', 'created')->count()))->toBe(3);
});

it('edits a draft, and freezes an order once issued', function () {
    rcSignIn('owner@mekanikomore.ph');
    $draft = $this->world->id('shop-po:coolant-draft');

    $this->patchJson("/api/v1/shop-purchase-orders/{$draft}", ['notes' => 'Edited', 'lines' => [['item_id' => $this->world->id('item:08889-80015'), 'quantity' => 10, 'unit_cost_cents' => 100000]]])
        ->assertOk()->assertJsonPath('data.total_cents', 1000000)->assertJsonPath('data.notes', 'Edited');

    $this->postJson("/api/v1/shop-purchase-orders/{$draft}/issue")->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.can_edit', false);
    $this->postJson("/api/v1/shop-purchase-orders/{$draft}/issue")->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->patchJson("/api/v1/shop-purchase-orders/{$draft}", ['notes' => 'Too late'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    // The database holds the same line: an issued order's lines and header never change.
    $line = asSystem(fn () => ShopPurchaseOrderLine::query()->where('shop_purchase_order_id', $draft)->firstOrFail());
    expect(fn () => DB::transaction(fn () => DB::table('shop_purchase_order_lines')->where('id', $line->id)->update(['quantity' => 99, 'line_total_cents' => 9900000])))
        ->toThrow(QueryException::class, 'an issued purchase order');
    expect(fn () => DB::transaction(fn () => DB::table('shop_purchase_orders')->where('id', $draft)->update(['total_cents' => 1])))
        ->toThrow(QueryException::class, 'only its status may change');
    expect(fn () => DB::transaction(fn () => DB::table('shop_purchase_orders')->where('id', $draft)->delete()))
        ->toThrow(QueryException::class, 'never deleted');
});

it('refuses a bad order: an empty one, an inactive vendor, an unstocked item, a job line that is not for a job', function () {
    rcSignIn('owner@mekanikomore.ph');
    $vendor = vendorId('Isuzu Alabang Service');
    $base = ['branch_id' => $this->repair, 'vendor_id' => $vendor];

    $this->postJson('/api/v1/shop-purchase-orders', [...$base, 'lines' => []])->assertUnprocessable();
    $this->postJson('/api/v1/shop-purchase-orders', [...$base, 'lines' => [['quantity' => 1, 'unit_cost_cents' => 100]]])
        ->assertUnprocessable()->assertJsonPath('error.details.fields', fn (array $f) => isset($f['lines.0.item_id']));
    $this->postJson('/api/v1/shop-purchase-orders', [...$base, 'lines' => [['item_id' => $this->world->id('item:SVC-DIAG'), 'quantity' => 1, 'unit_cost_cents' => 100]]])
        ->assertUnprocessable()->assertJsonPath('error.details.fields', fn (array $f) => isset($f['lines.0.item_id']));
    $this->postJson('/api/v1/shop-purchase-orders', [...$base, 'lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'quantity' => 0, 'unit_cost_cents' => 100]]])->assertUnprocessable();
    $this->postJson('/api/v1/shop-purchase-orders', [...$base, 'lines' => [['work_order_line_id' => $this->world->id('rival:work-order-line'), 'description' => 'Part', 'quantity' => 1, 'unit_cost_cents' => 100]]])
        ->assertUnprocessable();

    asSystem(fn () => Vendor::query()->whereKey($vendor)->update(['is_active' => false]));
    $this->postJson('/api/v1/shop-purchase-orders', [...$base, 'lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'quantity' => 1, 'unit_cost_cents' => 100]]])
        ->assertUnprocessable()->assertJsonPath('error.details.fields', fn (array $f) => isset($f['vendor_id']));
});

it('receives in part, then in full, and the status follows the receipts', function () {
    rcSignIn('owner@mekanikomore.ph');
    $po = $this->world->id('shop-po:top-up');
    $order = $this->getJson("/api/v1/shop-purchase-orders/{$po}")->assertOk()->json('data');
    [$filters, $pads] = $order['lines'];

    expect($order['status'])->toBe('partially_received')
        ->and($order['can_receive'])->toBeTrue()
        ->and($order['can_cancel'])->toBeFalse()
        ->and($filters['received_quantity'])->toBe('1.000')
        ->and($filters['outstanding_quantity'])->toBe('2.000')
        ->and($pads['outstanding_quantity'])->toBe('0.000')
        ->and($order['receipts'])->toHaveCount(1)
        ->and(onHandOf('90915-YZZD4', $this->repair))->toBe(['on_hand' => '13.000', 'avg_cost_cents' => 35231]);

    // More than is outstanding is refused, and so is a line that is not on the order.
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $filters['id'], 'quantity' => '2.001']]])
        ->assertUnprocessable()->assertJsonPath('error.details.fields', fn (array $f) => isset($f['lines.0.quantity']));
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $pads['id'], 'quantity' => '1']]])->assertUnprocessable();
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $this->world->id('rival:shop-po-line'), 'quantity' => '1']]])->assertUnprocessable();
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => []])->assertUnprocessable();

    // Two more boxes at the PO price: 20 pieces at ₱350 join 13 at ₱352.31.
    $receipt = $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", [
        'supplier_ref' => 'DR-48299',
        'lines' => [['shop_purchase_order_line_id' => $filters['id'], 'quantity' => '2']],
    ])->assertCreated();

    $receipt
        ->assertJsonPath('data.reference', 'GR-2026-0002')
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.received_on', '2026-10-08')
        ->assertJsonPath('data.supplier_ref', 'DR-48299')
        ->assertJsonPath('data.total_cents', 700000)
        // Bought by the box, kept by the piece.
        ->assertJsonPath('data.lines.0.quantity', '2.000')
        ->assertJsonPath('data.lines.0.unit_cost_cents', 350000)
        ->assertJsonPath('data.lines.0.stock_quantity', '20.000')
        ->assertJsonPath('data.lines.0.stock_unit_cost_cents', 35000)
        ->assertJsonPath('data.lines.0.item.sku', '90915-YZZD4');

    expect(onHandOf('90915-YZZD4', $this->repair))->toBe(['on_hand' => '33.000', 'avg_cost_cents' => 35091]);

    $after = $this->getJson("/api/v1/shop-purchase-orders/{$po}")->json('data');
    expect($after['status'])->toBe('received')
        ->and($after['can_receive'])->toBeFalse()
        ->and($after['lines'][0]['outstanding_quantity'])->toBe('0.000')
        ->and($after['receipts'])->toHaveCount(2);

    // Nothing left to receive.
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $filters['id'], 'quantity' => '1']]])
        ->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    // The move is on the ledger, tied to the receipt that made it.
    $moves = $this->getJson('/api/v1/stock/moves?move_type=receipt&item_id='.$this->world->id('item:90915-YZZD4'))->assertOk()->json('data');
    expect($moves[0]['quantity'])->toBe('20.000')
        ->and($moves[0]['unit_cost_cents'])->toBe(35000)
        ->and($moves[0]['value_cents'])->toBe(700000)
        ->and($moves[0]['source_reference'])->toBe('GR-2026-0002')
        ->and($moves[0]['actor_name'])->toBe('Mike Manabat');
});

it('receives against the order\'s price unless the invoice says otherwise', function () {
    rcSignIn('owner@mekanikomore.ph');
    $po = $this->world->id('shop-po:top-up');
    $filters = $this->getJson("/api/v1/shop-purchase-orders/{$po}")->json('data.lines.0.id');

    // The supplier billed ₱3,600 a box this time: the average follows the cost actually paid.
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $filters, 'quantity' => '1', 'unit_cost_cents' => 360000]]])
        ->assertCreated()
        ->assertJsonPath('data.lines.0.stock_unit_cost_cents', 36000)
        ->assertJsonPath('data.total_cents', 360000);

    // (3 × 360 + 10 × 350 + 10 × 360) ÷ 23 = 355.65
    expect(onHandOf('90915-YZZD4', $this->repair))->toBe(['on_hand' => '23.000', 'avg_cost_cents' => 35565]);
});

it('voids a receipt with compensating moves, never an edit', function () {
    rcSignIn('owner@mekanikomore.ph');
    $po = $this->world->id('shop-po:top-up');
    $receipt = $this->getJson("/api/v1/shop-purchase-orders/{$po}")->json('data.receipts.0.id');
    expect(onHandOf('04465-0K340', $this->repair)['on_hand'])->toBe('9.000');

    $this->postJson("/api/v1/goods-receipts/{$receipt}/void", ['reason' => ''])->assertUnprocessable();
    $this->postJson("/api/v1/goods-receipts/{$receipt}/void", ['reason' => 'Delivered to the wrong branch'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.void_reason', 'Delivered to the wrong branch')
        ->assertJsonPath('data.voided_by_name', 'Mike Manabat')
        ->assertJsonPath('data.can_void', false);

    // The pad sets and the box of filters are back where they were before the receipt.
    expect(onHandOf('04465-0K340', $this->repair)['on_hand'])->toBe('5.000')
        ->and(onHandOf('90915-YZZD4', $this->repair)['on_hand'])->toBe('3.000');

    // The order is nothing-received again, and can take the goods in properly.
    $order = $this->getJson("/api/v1/shop-purchase-orders/{$po}")->json('data');
    expect($order['status'])->toBe('issued')
        ->and($order['can_cancel'])->toBeTrue()
        ->and($order['lines'][0]['received_quantity'])->toBe('0.000');

    // The receipt stays on the books, and so do both moves of each line (R7).
    $moves = $this->getJson('/api/v1/stock/moves?source_type=goods_receipt&item_id='.$this->world->id('item:04465-0K340'))->json('data');
    expect(array_column($moves, 'move_type'))->toBe(['return', 'receipt'])
        ->and(array_column($moves, 'quantity'))->toBe(['-4.000', '4.000']);

    $this->postJson("/api/v1/goods-receipts/{$receipt}/void", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    expect(fn () => DB::transaction(fn () => DB::table('goods_receipts')->where('id', $receipt)->update(['total_cents' => 1])))->toThrow(QueryException::class, 'can only be voided');
    expect(fn () => DB::transaction(fn () => DB::table('goods_receipt_lines')->where('goods_receipt_id', $receipt)->delete()))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('goods_receipts')->where('id', $receipt)->delete()))->toThrow(QueryException::class, 'never deleted');

    $again = $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $order['lines'][0]['id'], 'quantity' => '3']]])->assertCreated();
    expect($again->json('data.reference'))->toBe('GR-2026-0002');
});

it('cancels an order nothing has been received against, with a reason', function () {
    rcSignIn('owner@mekanikomore.ph');
    $draft = $this->world->id('shop-po:coolant-draft');
    $topUp = $this->world->id('shop-po:top-up');

    $this->postJson("/api/v1/shop-purchase-orders/{$topUp}/cancel", ['reason' => 'Changed our mind'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->postJson("/api/v1/shop-purchase-orders/{$draft}/cancel", [])->assertUnprocessable();
    $this->postJson("/api/v1/shop-purchase-orders/{$draft}/cancel", ['reason' => 'Supplier out of stock'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Supplier out of stock')->assertJsonPath('data.can_cancel', false);
    $this->postJson("/api/v1/shop-purchase-orders/{$draft}/issue")->assertStatus(409);
    $this->postJson("/api/v1/shop-purchase-orders/{$draft}/receipts", ['lines' => [['shop_purchase_order_line_id' => $this->getJson("/api/v1/shop-purchase-orders/{$draft}")->json('data.lines.0.id'), 'quantity' => '1']]])->assertStatus(409);

    expect(array_column($this->getJson("/api/v1/shop-purchase-orders/{$draft}")->json('data.history'), 'status'))->toBe(['draft', 'cancelled']);
});

it('lists orders and receipts with filters, derived statuses included', function () {
    rcSignIn('owner@mekanikomore.ph');

    $refs = fn (string $query): array => array_column($this->getJson("/api/v1/shop-purchase-orders{$query}")->assertOk()->json('data'), 'reference');
    expect($refs(''))->toBe(['SPO-2026-0002', 'SPO-2026-0001'])
        ->and($refs('?status=draft'))->toBe(['SPO-2026-0002'])
        ->and($refs('?status=partially_received'))->toBe(['SPO-2026-0001'])
        ->and($refs('?status=open'))->toBe(['SPO-2026-0001'])
        ->and($refs('?status=received'))->toBe([])
        ->and($refs('?status=cancelled'))->toBe([])
        ->and($refs('?q=toyota'))->toBe(['SPO-2026-0001'])
        ->and($refs('?vendor_id='.vendorId('Isuzu Alabang Service')))->toBe(['SPO-2026-0002']);

    $this->getJson('/api/v1/shop-purchase-orders?status=nonsense')->assertUnprocessable();
    expect($this->getJson('/api/v1/shop-purchase-orders?status=partially_received')->json('meta.total'))->toBe(1);

    $receipts = $this->getJson('/api/v1/goods-receipts')->assertOk()->json('data');
    expect(array_column($receipts, 'reference'))->toBe(['GR-2026-0001'])
        ->and($receipts[0]['order_reference'])->toBe('SPO-2026-0001')
        ->and($receipts[0]['vendor_name'])->toBe('Toyota Shaw Service Center')
        ->and($receipts[0]['total_cents'])->toBe(350000 + 940000);
});

it('keeps purchasing to the branch the caller works in and to those who manage stock', function () {
    $po = $this->world->id('shop-po:top-up');
    $line = asSystem(fn () => ShopPurchaseOrderLine::query()->where('shop_purchase_order_id', $po)->orderBy('position')->firstOrFail()->id);

    // A branch manager pinned to the detailing branch cannot see, or touch, the repair shop's orders.
    rcSignIn('manager.samahuzai@mekanikomore.ph');
    $this->getJson("/api/v1/shop-purchase-orders/{$po}")->assertNotFound();
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $line, 'quantity' => 1]]])->assertNotFound();
    $this->postJson('/api/v1/shop-purchase-orders', ['branch_id' => $this->repair, 'vendor_id' => vendorId('Isuzu Alabang Service'), 'lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'quantity' => 1, 'unit_cost_cents' => 100]]])->assertNotFound();
    expect($this->getJson('/api/v1/shop-purchase-orders')->json('data'))->toBe([]);
    $this->getJson('/api/v1/goods-receipts/'.asSystem(fn () => GoodsReceipt::query()->where('shop_purchase_order_id', $po)->firstOrFail()->id))->assertNotFound();

    // They may raise one for their own branch.
    $this->postJson('/api/v1/shop-purchase-orders', ['branch_id' => $this->world->id('samahuzai-binan'), 'vendor_id' => vendorId('Isuzu Alabang Service'), 'lines' => [['item_id' => $this->world->id('item:DTL-MF-CLOTH'), 'quantity' => 50, 'unit_cost_cents' => 4400]]])
        ->assertCreated()->assertJsonPath('data.branch_id', $this->world->id('samahuzai-binan'));

    // The front counter reads the repair shop's orders but cannot change them.
    rcSignIn('cashier@mekanikomore.ph');
    $this->getJson("/api/v1/shop-purchase-orders/{$po}")->assertOk();
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/issue")->assertForbidden();
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $line, 'quantity' => 1]]])->assertForbidden();
});

it('ties each receipt and move to the audit trail', function () {
    rcSignIn('owner@mekanikomore.ph');
    $po = $this->world->id('shop-po:top-up');
    $line = $this->getJson("/api/v1/shop-purchase-orders/{$po}")->json('data.lines.0.id');
    $receipt = $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $line, 'quantity' => 1]]])->assertCreated()->json('data.id');

    expect(asSystem(fn () => AuditLog::query()->where('entity_type', 'goods_receipt')->where('entity_id', $receipt)->pluck('action')->all()))->toBe(['received'])
        ->and(asSystem(fn () => StockMove::query()->where('source_id', $receipt)->count()))->toBe(1)
        ->and(asSystem(fn () => GoodsReceipt::query()->where('shop_purchase_order_id', $po)->count()))->toBe(2);
});
