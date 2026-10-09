<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Stock only ever changes through moves. The ledger is append-only; a balance
 * can be written only by the ledger service; and at commit every balance must
 * equal the sum of its moves. This file proves each of those at the database
 * and then reconciles the whole seeded world, before and after a busy day.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->repair = $this->world->id('mekanikomor-binan');
    $this->store = $this->world->id('location:mekanikomor-binan');
});

/**
 * Every (location, item) whose balance is not the sum of its moves, or that
 * has moves and no balance, or a balance and no moves.
 *
 * @return list<string>
 */
function unreconciled(): array
{
    return array_map(fn (object $row): string => "{$row->location_id}/{$row->item_id}: balance {$row->on_hand} but moves sum to {$row->summed}", DB::select(<<<'SQL'
        select coalesce(b.location_id, m.location_id) as location_id, coalesce(b.item_id, m.item_id) as item_id,
               coalesce(b.on_hand, 0) as on_hand, coalesce(m.summed, 0) as summed
        from stock_balances b
        full outer join (select location_id, item_id, sum(quantity) as summed from stock_moves group by location_id, item_id) m
          on m.location_id = b.location_id and m.item_id = b.item_id
        where b.on_hand is distinct from m.summed
        SQL));
}

it('writes a balance only through the ledger', function () {
    // The seed posted through the ledger, which switched the guard on for this whole test transaction:
    // switch it off, as a write arriving from outside the ledger would find it.
    DB::select("select set_config('torquelane.stock_ledger', '', true)");

    expect(fn () => DB::transaction(fn () => DB::table('stock_balances')->update(['on_hand' => 1000])))->toThrow(QueryException::class, 'only through the stock ledger')
        ->and(fn () => DB::transaction(fn () => DB::table('stock_balances')->delete()))->toThrow(QueryException::class, 'only through the stock ledger')
        ->and(fn () => DB::transaction(fn () => DB::table('stock_balances')->insert([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $this->world->id('prov-mekanikomore'),
            'branch_id' => $this->repair,
            'location_id' => $this->store,
            'item_id' => $this->world->id('item:CAM-BLT-A'),
            'on_hand' => 50,
            'avg_cost_cents' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'only through the stock ledger');
});

it('never updates or deletes a move', function () {
    $id = DB::table('stock_moves')->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('stock_moves')->where('id', $id)->update(['quantity' => 999])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('stock_moves')->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only');
});

it('holds a move to its type\'s direction, an adjustment to a reason, and a source to be named', function () {
    $base = [
        'organization_id' => $this->world->id('prov-mekanikomore'),
        'branch_id' => $this->repair,
        'location_id' => $this->store,
        'item_id' => $this->world->id('item:RAG-SHOP'),
        'quantity' => 1,
        'unit_cost_cents' => 100,
        'move_type' => 'receipt',
        'source_type' => 'manual',
        'source_id' => null,
        'occurred_at' => now(),
        'actor_name' => 'Test',
        'reason' => null,
        'negative_flag' => false,
    ];
    $insert = fn (array $override): callable => fn () => DB::transaction(fn () => DB::table('stock_moves')->insert([...$base, 'id' => strtolower((string) Str::ulid()), ...$override]));

    expect($insert(['quantity' => -1]))->toThrow(QueryException::class, 'stock_moves_quantity_check')
        ->and($insert(['move_type' => 'issue', 'quantity' => 1]))->toThrow(QueryException::class, 'stock_moves_quantity_check')
        ->and($insert(['quantity' => 0]))->toThrow(QueryException::class, 'stock_moves_quantity_check')
        ->and($insert(['unit_cost_cents' => -1]))->toThrow(QueryException::class, 'stock_moves_quantity_check')
        ->and($insert(['move_type' => 'adjustment', 'reason' => '  ']))->toThrow(QueryException::class, 'stock_moves_adjustment_reason_check')
        ->and($insert(['source_type' => 'goods_receipt', 'source_id' => null]))->toThrow(QueryException::class, 'stock_moves_source_check')
        ->and($insert(['move_type' => 'teleport']))->toThrow(QueryException::class)
        // Another branch's location cannot carry this branch's move.
        ->and($insert(['branch_id' => $this->world->id('samahuzai-binan')]))->toThrow(QueryException::class);
});

it('reconciles the seeded world: every balance is the sum of its moves', function () {
    expect(unreconciled())->toBe([])
        ->and(DB::table('stock_moves')->count())->toBeGreaterThan(15)
        ->and(DB::table('stock_balances')->count())->toBeGreaterThan(10);

    // And the database agrees at its own commit point (the deferred checks, run now).
    DB::statement('set constraints all immediate');
    expect(true)->toBeTrue();
});

it('refuses, at commit, a balance that no longer matches its moves', function () {
    DB::statement('set constraints all immediate');

    expect(fn () => DB::transaction(function (): void {
        DB::select("select set_config('torquelane.stock_ledger', 'on', true)");
        DB::table('stock_balances')->where('location_id', $this->store)->where('item_id', $this->world->id('item:RAG-SHOP'))->update(['on_hand' => DB::raw('on_hand + 1')]);
        DB::statement('set constraints all immediate');
    }))->toThrow(QueryException::class, 'but its moves sum to');

    // A move with no balance following it is just as wrong.
    expect(fn () => DB::transaction(function (): void {
        DB::table('stock_moves')->insert([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $this->world->id('prov-mekanikomore'),
            'branch_id' => $this->repair,
            'location_id' => $this->store,
            'item_id' => $this->world->id('item:RAG-SHOP'),
            'quantity' => 1,
            'unit_cost_cents' => 100,
            'move_type' => 'return',
            'source_type' => 'manual',
            'occurred_at' => now(),
            'actor_name' => 'Test',
            'negative_flag' => false,
        ]);
        DB::statement('set constraints all immediate');
    }))->toThrow(QueryException::class, 'but its moves sum to');
});

it('still reconciles after a busy day through the API, and the value adds up', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $detailing = $this->world->id('location:samahuzai-binan');
    $item = fn (string $sku): string => $this->world->id("item:{$sku}");

    // Goods in (part, then rest of the order, then one receipt voided).
    $po = $this->world->id('shop-po:top-up');
    $filters = $this->getJson("/api/v1/shop-purchase-orders/{$po}")->json('data.lines.0.id');
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $filters, 'quantity' => '1.5', 'unit_cost_cents' => 351000]]])->assertCreated();
    $second = $this->postJson("/api/v1/shop-purchase-orders/{$po}/receipts", ['lines' => [['shop_purchase_order_line_id' => $filters, 'quantity' => '0.5']]])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/goods-receipts/{$second}/void", ['reason' => 'Miscounted'])->assertOk();

    // Things moving about.
    $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $this->store, 'to_location_id' => $detailing, 'lines' => [['item_id' => $item('HX7-5W30-1L'), 'quantity' => '12.5'], ['item_id' => $item('RAG-SHOP'), 'quantity' => '3']]])->assertCreated();
    $transfer = $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $detailing, 'to_location_id' => $this->store, 'lines' => [['item_id' => $item('DTL-SHAMPOO-5L'), 'quantity' => '1']]])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/stock-transfers/{$transfer}/reverse")->assertCreated();

    // A count that finds things where they should not be.
    $count = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->store, 'reason' => 'Annual count'])->assertCreated()->json('data');
    $this->putJson("/api/v1/stock-counts/{$count['id']}/lines", ['lines' => [
        ['item_id' => $item('04465-0K340'), 'counted_quantity' => '8.25'],
        ['item_id' => $item('CAM-BLT-A'), 'counted_quantity' => '2'],
    ]])->assertOk();
    $this->postJson("/api/v1/stock-counts/{$count['id']}/post")->assertOk();

    // A job: issue, a second line, then cancel.
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $order = $this->postJson('/api/v1/work-orders', ['vehicle_id' => $this->world->id('veh-001'), 'title' => 'Service', 'type' => 'corrective', 'lines' => [
        ['description' => 'Filter', 'quantity' => 2, 'parts_source' => 'shop_stock', 'item_id' => $item('90915-YZZD4')],
        ['description' => 'Pads', 'quantity' => 1, 'parts_source' => 'shop_stock', 'item_id' => $item('04465-0K340')],
    ]])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/work-orders/{$order}/send")->assertOk();
    $this->postJson("/api/v1/work-orders/{$order}/schedule", ['scheduled_for' => '2026-10-09', 'scheduled_time' => '09:00', 'bay_id' => $this->world->id('bay:bay-2')])->assertOk();
    Sanctum::actingAs($this->world->user('bay@mekanikomore.ph'));
    $this->postJson("/api/v1/work-orders/{$order}/start")->assertOk();
    $this->postJson("/api/v1/work-orders/{$order}/complete", ['findings' => 'Done'])->assertOk();
    $this->postJson("/api/v1/work-orders/{$order}/close")->assertOk();

    expect(unreconciled())->toBe([]);
    DB::statement('set constraints all immediate');

    // The value the API reports is the exact sum of on hand × average, rounded once.
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $expected = (int) DB::scalar('select round(coalesce(sum(on_hand * avg_cost_cents), 0)) from stock_balances where organization_id = ?', [$this->world->id('prov-mekanikomore')]);
    $summary = $this->getJson('/api/v1/stock/on-hand?per_page=100')->assertOk()->json('summary');
    $rows = $this->getJson('/api/v1/stock/on-hand?per_page=100')->json('data');
    expect($summary['value_cents'])->toBe($expected)
        ->and($summary['lines'])->toBe(count($rows))
        ->and(abs(array_sum(array_column($rows, 'value_cents')) - $summary['value_cents']))->toBeLessThanOrEqual(count($rows));
});
