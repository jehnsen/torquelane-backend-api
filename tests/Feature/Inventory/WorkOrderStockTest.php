<?php

declare(strict_types=1);

use App\Models\StockMove;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Where a work-order line's parts come from, and what the stock room does
 * about it. Seed: the repair store holds 13 oil filters (₱352.31 average),
 * 9 brake pad sets (₱2,350) and 6 air filters (₱850); the oil filter is priced
 * ₱520 at the shop. Actimed's auto-approve band is under ₱5,000 pre-tax; the
 * work_order series continues at 1656.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->repair = $this->world->id('mekanikomor-binan');
    $this->oil = $this->world->id('item:90915-YZZD4');
});

function woSignIn(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @return array<string, mixed>
 */
function woRaise(array $lines, string $title = 'Service'): array
{
    woSignIn('advisor@mekanikomore.ph');

    return test()->postJson('/api/v1/work-orders', [
        'vehicle_id' => test()->world->id('veh-001'),
        'title' => $title,
        'type' => 'corrective',
        'lines' => $lines,
    ])->assertCreated()->json('data');
}

/** Send, schedule and start an order the customer's band approves on its own. */
function woStart(string $id): void
{
    woSignIn('advisor@mekanikomore.ph');
    test()->postJson("/api/v1/work-orders/{$id}/send")->assertOk();
    test()->postJson("/api/v1/work-orders/{$id}/schedule", ['scheduled_for' => '2026-10-09', 'scheduled_time' => '09:00', 'bay_id' => test()->world->id('bay:bay-2')])->assertOk();
    woSignIn('bay@mekanikomore.ph');
    test()->postJson("/api/v1/work-orders/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
}

/** Shop rags: priced at nothing, so any quantity stays inside the auto-approval band. */
function rags(int $quantity): array
{
    return ['description' => 'Shop rags', 'quantity' => $quantity, 'parts_source' => 'shop_stock', 'item_id' => test()->world->id('item:RAG-SHOP')];
}

function filterLine(array $extra = []): array
{
    return array_replace(['description' => 'Oil filter', 'quantity' => 2, 'labour_hours' => 0.5, 'parts_source' => 'shop_stock', 'item_id' => test()->oil], $extra);
}

/** @return array{on_hand: string, avg_cost_cents: int} */
function shelf(string $sku): array
{
    woSignIn('owner@mekanikomore.ph');
    $branch = collect(test()->getJson('/api/v1/items/'.test()->world->id("item:{$sku}"))->json('data.branches'))->firstWhere('branch_id', test()->repair);

    return ['on_hand' => $branch['on_hand'], 'avg_cost_cents' => $branch['avg_cost_cents']];
}

function movesOfOrder(string $orderId): array
{
    return asSystem(fn () => StockMove::query()
        ->whereIn('source_id', DB::table('work_order_lines')->where('work_order_id', $orderId)->pluck('id')->all())
        ->orderBy('id')->get()->all());
}

// ------------------------------------------------------------------ lines

it('prices a shop-stock line at the branch\'s price, and a customer\'s own part at nothing', function () {
    $order = woRaise([
        filterLine(),
        ['description' => 'Customer brings the brake pads', 'quantity' => 1, 'labour_hours' => 1, 'parts_source' => 'customer_supplied', 'unit_part_rate_cents' => 500000],
        ['description' => 'Legacy line', 'quantity' => 1, 'unit_part_rate_cents' => 12000, 'parts_source' => 'own_stock'],
    ]);

    [$shelf, $theirs, $legacy] = $order['lines'];
    expect($shelf['parts_source'])->toBe('shop_stock')
        ->and($shelf['item_id'])->toBe($this->oil)
        ->and($shelf['item']['sku'])->toBe('90915-YZZD4')
        ->and($shelf['unit_part_rate_cents'])->toBe(52000)
        ->and($shelf['part_cost_cents'])->toBe(104000)
        // Whatever the client sent, the part the customer brought is not charged.
        ->and($theirs['parts_source'])->toBe('customer_supplied')
        ->and($theirs['unit_part_rate_cents'])->toBe(0)
        ->and($theirs['part_cost_cents'])->toBe(0)
        ->and($theirs['labour_cost_cents'])->toBe(65000)
        // Phase 3's values mean what they always did.
        ->and($legacy['part_cost_cents'])->toBe(12000)
        ->and($order['totals']['parts_total_cents'])->toBe(116000)
        ->and($order['lines'][0]['stock_cost_cents'])->toBeNull();

    // A branch price override applies; a rate the advisor types wins over both.
    woSignIn('owner@mekanikomore.ph');
    $this->putJson("/api/v1/items/{$this->oil}/branch-settings/{$this->repair}", ['price_override_cents' => 49900])->assertOk();
    $again = woRaise([filterLine(), filterLine(['unit_part_rate_cents' => 60000])]);
    expect($again['lines'][0]['unit_part_rate_cents'])->toBe(49900)->and($again['lines'][1]['unit_part_rate_cents'])->toBe(60000);
});

it('asks shop-stock lines for an inventory item, and nobody else', function () {
    woSignIn('advisor@mekanikomore.ph');
    $draft = ['vehicle_id' => $this->world->id('veh-001'), 'title' => 'Service', 'type' => 'corrective'];

    $this->postJson('/api/v1/work-orders', [...$draft, 'lines' => [filterLine(['item_id' => null])]])->assertUnprocessable();
    $this->postJson('/api/v1/work-orders', [...$draft, 'lines' => [filterLine(['parts_source' => 'supplier_provided'])]])->assertUnprocessable();
    $this->postJson('/api/v1/work-orders', [...$draft, 'lines' => [filterLine(['item_id' => $this->world->id('item:SVC-DIAG')])]])->assertUnprocessable();
    $this->postJson('/api/v1/work-orders', [...$draft, 'lines' => [filterLine(['item_id' => $this->world->id('rival:item')])]])->assertUnprocessable();

    // An inactive item is not issued.
    woSignIn('owner@mekanikomore.ph');
    $this->patchJson("/api/v1/items/{$this->oil}", ['is_active' => false])->assertOk();
    woSignIn('advisor@mekanikomore.ph');
    $this->postJson('/api/v1/work-orders', [...$draft, 'lines' => [filterLine()]])->assertUnprocessable();
});

it('lets the settings default a line\'s source to any that needs no item', function () {
    woSignIn('owner@mekanikomore.ph');
    $this->putJson('/api/v1/approval-settings', ['default_parts_source' => 'customer_supplied'])->assertOk();
    $this->getJson('/api/v1/approval-settings')->assertJsonPath('data.organization.default_parts_source', 'customer_supplied');
    // A default cannot be a source that needs a particular item.
    $this->putJson('/api/v1/approval-settings', ['default_parts_source' => 'shop_stock'])->assertUnprocessable();
    $this->putJson('/api/v1/approval-settings', ['default_parts_source' => 'purchased_for_job'])->assertOk();

    $order = woRaise([['description' => 'Anything', 'unit_part_rate_cents' => 10000]]);
    expect($order['lines'][0]['parts_source'])->toBe('purchased_for_job');
});

// ------------------------------------------------------------------ issues

it('issues the shelf when the work is recorded, costs the job from the moves, and leaves the price alone', function () {
    $order = woRaise([filterLine()]);
    $id = $order['id'];
    $lineId = $order['lines'][0]['id'];
    woStart($id);

    // Under way is not yet done: nothing has left the shelf.
    expect(shelf('90915-YZZD4')['on_hand'])->toBe('13.000')->and(movesOfOrder($id))->toBe([]);

    woSignIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/complete", ['findings' => 'Filter replaced.'])->assertOk();

    $moves = movesOfOrder($id);
    expect($moves)->toHaveCount(1)
        ->and($moves[0]->move_type->value)->toBe('issue')
        ->and((string) $moves[0]->quantity)->toBe('-2.000')
        ->and($moves[0]->unit_cost_cents)->toBe(35231)
        ->and($moves[0]->source_id)->toBe($lineId)
        ->and($moves[0]->reason)->toBe('Issued to WO-2026-1656')
        ->and($moves[0]->actor_name)->toBe('Arnel Pascual')
        ->and($moves[0]->negative_flag)->toBeFalse()
        ->and(shelf('90915-YZZD4'))->toBe(['on_hand' => '11.000', 'avg_cost_cents' => 35231]);

    // Recording the work again, then closing it, posts nothing more: the order is in step.
    $this->postJson("/api/v1/work-orders/{$id}/complete", ['findings' => 'Filter replaced; seal checked.'])->assertOk();
    expect(movesOfOrder($id))->toHaveCount(1);

    // The job's cost is what the moves cost; its price is the approved line, untouched.
    woSignIn('advisor@mekanikomore.ph');
    $view = $this->getJson("/api/v1/work-orders/{$id}")->assertOk()->json('data');
    expect($view['lines'][0]['part_cost_cents'])->toBe(104000)
        ->and($view['lines'][0]['stock_cost_cents'])->toBe(70462)
        ->and($view['stock'])->toBe(['cost_cents' => 70462, 'price_cents' => 104000, 'margin_cents' => 33538]);

    woSignIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    expect(movesOfOrder($id))->toHaveCount(1)
        ->and(shelf('90915-YZZD4')['on_hand'])->toBe('11.000');

    // Each move is on the item's ledger, naming the order.
    woSignIn('owner@mekanikomore.ph');
    $ledger = $this->getJson("/api/v1/stock/moves?item_id={$this->oil}&move_type=issue")->json('data');
    expect($ledger)->toHaveCount(1)
        ->and($ledger[0]['source_type'])->toBe('work_order_line')
        ->and($ledger[0]['source_reference'])->toBe('WO-2026-1656')
        ->and($ledger[0]['value_cents'])->toBe(70462);
});

it('keeps the shop\'s costs out of the customer\'s view', function () {
    $order = woRaise([filterLine()]);
    woStart($order['id']);
    woSignIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$order['id']}/complete", ['findings' => 'Done'])->assertOk();

    woSignIn('donmiguel@mekanikomor.ph');
    $theirs = $this->getJson("/api/v1/work-orders/{$order['id']}")->assertOk()->json('data');

    expect($theirs['lines'][0]['parts_source'])->toBe('shop_stock')
        ->and($theirs['lines'][0]['part_cost_cents'])->toBe(104000)
        ->and($theirs['lines'][0]['item_id'])->toBeNull()
        ->and($theirs['lines'][0]['item'])->toBeNull()
        ->and($theirs['lines'][0]['stock_cost_cents'])->toBeNull()
        ->and($theirs['stock'])->toBeNull()
        ->and(json_encode($theirs))->not->toContain($this->oil);
});

it('issues only the lines the customer approved', function () {
    // Over the auto-approval band, so the customer decides line by line.
    $order = woRaise([
        ['description' => 'Brake pads', 'quantity' => 3, 'labour_hours' => 1, 'parts_source' => 'shop_stock', 'item_id' => $this->world->id('item:04465-0K340')],
        ['description' => 'Air filter', 'quantity' => 1, 'labour_hours' => 0.25, 'parts_source' => 'shop_stock', 'item_id' => $this->world->id('item:17801-0L040')],
    ]);
    $id = $order['id'];
    [$pads, $air] = array_column($order['lines'], 'id');
    expect($order['lines'][0]['unit_part_rate_cents'])->toBe(320000);

    woSignIn('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    woSignIn('donmiguel@mekanikomor.ph');
    $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => [
        ['line_id' => $pads, 'decision' => 'approved'],
        ['line_id' => $air, 'decision' => 'declined', 'note' => 'We changed it last month.'],
    ]])->assertOk()->assertJsonPath('data.status', 'partially_approved');

    woSignIn('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/schedule", ['scheduled_for' => '2026-10-09', 'scheduled_time' => '09:00', 'bay_id' => $this->world->id('bay:bay-2')])->assertOk();
    woSignIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/start")->assertOk();
    $this->postJson("/api/v1/work-orders/{$id}/complete", ['findings' => 'Done'])->assertOk();

    expect(shelf('04465-0K340')['on_hand'])->toBe('6.000')
        ->and(shelf('17801-0L040')['on_hand'])->toBe('6.000')
        ->and(array_map(fn (StockMove $m): string => (string) $m->quantity, movesOfOrder($id)))->toBe(['-3.000']);

    woSignIn('advisor@mekanikomore.ph');
    $view = $this->getJson("/api/v1/work-orders/{$id}")->json('data');
    // 3 × ₱2,350 cost against 3 × ₱3,200 approved.
    expect($view['stock'])->toBe(['cost_cents' => 705000, 'price_cents' => 960000, 'margin_cents' => 255000])
        // Nothing was issued for the line the customer declined: no cost yet, not a cost of nothing.
        ->and($view['lines'][1]['stock_cost_cents'])->toBeNull();
});

it('puts back what a cancelled job took, with a compensating move and never an edit', function () {
    $order = woRaise([filterLine(['quantity' => 3])]);
    $id = $order['id'];
    woStart($id);
    woSignIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/complete", ['findings' => 'Done'])->assertOk();
    expect(shelf('90915-YZZD4')['on_hand'])->toBe('10.000');
    $issue = movesOfOrder($id)[0];

    woSignIn('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/cancel", ['reason' => 'Customer took the vehicle elsewhere.'])->assertOk()->assertJsonPath('data.status', 'cancelled');

    $moves = movesOfOrder($id);
    expect($moves)->toHaveCount(2)
        ->and($moves[0]->id)->toBe($issue->id)
        ->and((string) $moves[0]->quantity)->toBe('-3.000')
        ->and($moves[1]->move_type->value)->toBe('return')
        ->and((string) $moves[1]->quantity)->toBe('3.000')
        // Back on the shelf at the cost it left at.
        ->and($moves[1]->unit_cost_cents)->toBe(35231)
        ->and(shelf('90915-YZZD4'))->toBe(['on_hand' => '13.000', 'avg_cost_cents' => 35231]);

    $view = $this->getJson("/api/v1/work-orders/{$id}")->json('data');
    expect($view['stock']['cost_cents'])->toBe(0)->and($view['lines'][0]['stock_cost_cents'])->toBe(0);

    // A job cancelled before it started never touched the shelf.
    $early = woRaise([filterLine()], 'Early cancel');
    woSignIn('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$early['id']}/cancel", ['reason' => 'Mistake'])->assertOk();
    expect(movesOfOrder($early['id']))->toBe([]);
});

it('refuses to complete a job the branch cannot supply when it blocks negative stock, and rolls it all back', function () {
    woSignIn('owner@mekanikomore.ph');
    $this->patchJson("/api/v1/branches/{$this->repair}", ['negative_stock_policy' => 'block'])->assertOk();

    $order = woRaise([rags(11)]);
    woStart($order['id']);

    woSignIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$order['id']}/complete", ['findings' => 'Will not be kept'])
        ->assertStatus(409)
        ->assertJsonPath('error.details.reason', 'insufficient_stock')
        ->assertJsonPath('error.details.on_hand', '10')
        ->assertJsonPath('error.details.requested', '11');

    expect(movesOfOrder($order['id']))->toBe([])
        ->and(shelf('RAG-SHOP')['on_hand'])->toBe('10.000');
    $this->getJson("/api/v1/work-orders/{$order['id']}")->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.findings', '');
    $this->postJson("/api/v1/work-orders/{$order['id']}/close")->assertStatus(409);
});

it('lets the job go through where the branch only flags a shortage', function () {
    $order = woRaise([rags(11)]);
    woStart($order['id']);
    woSignIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$order['id']}/complete", ['findings' => 'Done'])->assertOk();

    $moves = movesOfOrder($order['id']);
    expect($moves[0]->negative_flag)->toBeTrue()
        ->and(shelf('RAG-SHOP')['on_hand'])->toBe('-1.000');

    woSignIn('owner@mekanikomore.ph');
    expect($this->getJson('/api/v1/stock/on-hand?low=1')->json('summary.negative'))->toBe(1);
});

it('settles the shelf on closing even when the work was never recorded first', function () {
    $order = woRaise([filterLine(['quantity' => 1])]);
    woStart($order['id']);
    woSignIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$order['id']}/close")->assertOk();

    expect(movesOfOrder($order['id']))->toHaveCount(1)->and(shelf('90915-YZZD4')['on_hand'])->toBe('12.000');
});

it('freezes an approved line\'s source and item, in the database as well', function () {
    $order = woRaise([filterLine()]);
    woSignIn('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$order['id']}/send")->assertOk()->assertJsonPath('data.status', 'approved');
    $lineId = $order['lines'][0]['id'];

    $this->putJson("/api/v1/work-orders/{$order['id']}/lines", ['lines' => [filterLine(['id' => $lineId, 'item_id' => $this->world->id('item:17801-0L040')])]])->assertStatus(409);

    expect(fn () => DB::transaction(fn () => DB::table('work_order_lines')->where('id', $lineId)->update(['item_id' => $this->world->id('item:17801-0L040')])))
        ->toThrow(QueryException::class, 'keeps the source')
        ->and(fn () => DB::transaction(fn () => DB::table('work_order_lines')->where('id', $lineId)->update(['parts_source' => 'own_stock', 'item_id' => null])))
        ->toThrow(QueryException::class, 'keeps the source');

    // The shape the database insists on: a shop-stock line names its item; a customer's own part costs nothing.
    expect(fn () => DB::transaction(fn () => DB::statement("update work_order_lines set item_id = null where id = '{$lineId}'")))->toThrow(QueryException::class);
});

// ---------------------------------------------------- bought for the job

it('costs a part bought for one job from the goods received against it, without touching the shelf', function () {
    $order = woRaise([['description' => 'Shock absorber', 'quantity' => 1, 'unit_part_rate_cents' => 300000, 'parts_source' => 'purchased_for_job']]);
    $id = $order['id'];
    $lineId = $order['lines'][0]['id'];
    $movesBefore = asSystem(fn () => StockMove::query()->count());

    woSignIn('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk()->assertJsonPath('data.status', 'approved');

    // The shop buys it for the job on a purchase order that names the line.
    woSignIn('owner@mekanikomore.ph');
    $vendor = asSystem(fn () => Vendor::query()->where('name', 'Bridgestone Tire Center')->firstOrFail()->id);
    $po = $this->postJson('/api/v1/shop-purchase-orders', ['branch_id' => $this->repair, 'vendor_id' => $vendor, 'lines' => [
        ['work_order_line_id' => $lineId, 'description' => 'Rear shock absorber', 'quantity' => 1, 'unit_cost_cents' => 210000],
    ]])->assertCreated();
    $poId = $po->json('data.id');
    expect($po->json('data.lines.0.item'))->toBeNull()->and($po->json('data.lines.0.work_order_line_id'))->toBe($lineId);

    $this->postJson("/api/v1/shop-purchase-orders/{$poId}/issue")->assertOk();
    $this->postJson("/api/v1/shop-purchase-orders/{$poId}/receipts", ['lines' => [['shop_purchase_order_line_id' => $po->json('data.lines.0.id'), 'quantity' => 1, 'unit_cost_cents' => 215000]]])
        ->assertCreated()->assertJsonPath('data.lines.0.item', null)->assertJsonPath('data.total_cents', 215000);
    $this->getJson("/api/v1/shop-purchase-orders/{$poId}")->assertJsonPath('data.status', 'received');

    // The shelf never saw it; the job is costed at what was actually paid.
    expect(asSystem(fn () => StockMove::query()->count()))->toBe($movesBefore);
    $view = $this->getJson("/api/v1/work-orders/{$id}")->json('data');
    expect($view['stock'])->toBe(['cost_cents' => 215000, 'price_cents' => 300000, 'margin_cents' => 85000])
        ->and($view['lines'][0]['stock_cost_cents'])->toBe(215000);

    // Voiding the receipt takes the cost back off the job.
    $receipt = $this->getJson("/api/v1/shop-purchase-orders/{$poId}")->json('data.receipts.0.id');
    $this->postJson("/api/v1/goods-receipts/{$receipt}/void", ['reason' => 'Wrong part'])->assertOk();
    expect($this->getJson("/api/v1/work-orders/{$id}")->json('data.stock.cost_cents'))->toBe(0);
});
