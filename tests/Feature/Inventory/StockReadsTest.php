<?php

declare(strict_types=1);

use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Reading the stock room: locations, stock on hand with its value, the item
 * movements ledger, low-stock alerts (derived on read) and the Reorder view,
 * which reads on hand, on order, the reorder points and Phase 4's fleet
 * forecast together. The same frozen world as the other inventory tests, in
 * which Actimed's 6-week forecast is short 4 oil filters and 1 camber bolt.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->repair = $this->world->id('mekanikomor-binan');
    $this->detailing = $this->world->id('samahuzai-binan');
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
});

it('lists the stock locations of the branches the caller works in', function () {
    $locations = $this->getJson('/api/v1/stock-locations')->assertOk()->json('data');
    expect(array_column($locations, 'name'))->toBe(['MekanikoMoR-Biñan store', 'Samahuzai-Biñan store'])
        ->and(array_column($locations, 'kind'))->toBe(['store', 'store']);

    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    expect(array_column($this->getJson('/api/v1/stock-locations')->json('data'), 'branch_id'))->toBe([$this->detailing]);

    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $this->getJson('/api/v1/stock-locations')->assertForbidden();
});

it('creates a store with each new branch', function () {
    $branch = $this->postJson('/api/v1/branches', ['name' => 'MekanikoMoR-Sta. Rosa', 'slug' => 'sta-rosa'])->assertCreated()->json('data.id');

    $locations = collect($this->getJson('/api/v1/stock-locations')->json('data'));
    expect($locations->firstWhere('branch_id', $branch)['name'])->toBe('MekanikoMoR-Sta. Rosa store');

    // A branch with a stock history is kept, not deleted.
    $this->deleteJson("/api/v1/branches/{$branch}")->assertNoContent();
    $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $this->world->id('location:mekanikomor-binan'), 'to_location_id' => $this->world->id('location:samahuzai-binan'), 'lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'quantity' => 1]]])->assertCreated();
    $this->deleteJson("/api/v1/branches/{$this->detailing}")->assertStatus(409);
});

it('shows stock on hand by SKU with what it is worth', function () {
    $response = $this->getJson("/api/v1/stock/on-hand?branch_id={$this->repair}&per_page=100")->assertOk();
    $rows = collect($response->json('data'))->keyBy('item.sku');

    expect($rows->keys()->all())->toBe(['04465-0K340', '08889-80015', '17801-0L040', '90915-YZZD4', 'DTL-MF-CLOTH', 'HX7-5W30-1L', 'RAG-SHOP', 'WPR-BLD-22'])
        ->and($rows['90915-YZZD4']['on_hand'])->toBe('13.000')
        ->and($rows['90915-YZZD4']['avg_cost_cents'])->toBe(35231)
        ->and($rows['90915-YZZD4']['value_cents'])->toBe(458003)
        ->and($rows['90915-YZZD4']['reorder_point'])->toBe('8.000')
        ->and($rows['90915-YZZD4']['bin'])->toBe('A-01')
        ->and($rows['90915-YZZD4']['location_name'])->toBe('MekanikoMoR-Biñan store')
        ->and($rows['90915-YZZD4']['is_low'])->toBeFalse()
        // Six on hand against a reorder point of six.
        ->and($rows['17801-0L040']['is_low'])->toBeTrue()
        // Received 4 at the same cost it opened at: the average did not move.
        ->and($rows['04465-0K340']['on_hand'])->toBe('9.000')
        ->and($rows['04465-0K340']['avg_cost_cents'])->toBe(235000)
        ->and($rows['DTL-MF-CLOTH']['reorder_point'])->toBeNull()
        ->and($response->json('summary'))->toBe(['lines' => 8, 'value_cents' => 7736003, 'low' => 1, 'negative' => 0]);

    // Every branch the caller may see, by default.
    $all = $this->getJson('/api/v1/stock/on-hand?per_page=100')->assertOk();
    expect($all->json('summary'))->toBe(['lines' => 10, 'value_cents' => 8166003, 'low' => 1, 'negative' => 0]);
});

it('filters stock on hand', function () {
    $skus = fn (string $query): array => array_column(array_column($this->getJson("/api/v1/stock/on-hand?branch_id={$this->repair}{$query}")->assertOk()->json('data'), 'item'), 'sku');

    expect($skus('&q=filter'))->toBe(['17801-0L040', '90915-YZZD4'])
        ->and($skus('&item_type=consumable'))->toBe(['08889-80015', 'DTL-MF-CLOTH', 'HX7-5W30-1L', 'RAG-SHOP'])
        ->and($skus('&low=1'))->toBe(['17801-0L040'])
        ->and($skus('&location_id='.$this->world->id('location:samahuzai-binan')))->toBe([]);

    $this->getJson("/api/v1/stock/on-hand?location_id={$this->world->id('location:samahuzai-binan')}")->assertOk()->assertJsonPath('summary.lines', 2);
    // Another organization's branch is a branch that does not exist.
    $this->getJson('/api/v1/stock/on-hand?branch_id='.$this->world->id('rival:branch'))->assertNotFound();

    // A pinned manager is held to their own branch.
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $this->getJson("/api/v1/stock/on-hand?branch_id={$this->repair}")->assertNotFound();
    expect($this->getJson('/api/v1/stock/on-hand')->assertOk()->json('summary.lines'))->toBe(2);
});

it('records an opening balance only for an item with no history there', function () {
    $store = $this->world->id('location:samahuzai-binan');
    $wipers = $this->world->id('item:WPR-BLD-22');

    $this->postJson('/api/v1/stock/opening', ['location_id' => $store, 'reason' => 'Initial stock', 'lines' => [['item_id' => $wipers, 'quantity' => '6', 'unit_cost_cents' => 61000]]])
        ->assertCreated()
        ->assertJsonPath('data.0.move_type', 'opening')
        ->assertJsonPath('data.0.quantity', '6.000')
        ->assertJsonPath('data.0.unit_cost_cents', 61000)
        ->assertJsonPath('data.0.reason', 'Initial stock')
        ->assertJsonPath('data.0.item.sku', 'WPR-BLD-22');

    // A second opening, an item with a history, or a repeated item is refused whole.
    $this->postJson('/api/v1/stock/opening', ['location_id' => $store, 'lines' => [['item_id' => $wipers, 'quantity' => '1', 'unit_cost_cents' => 100]]])->assertUnprocessable();
    $this->postJson('/api/v1/stock/opening', ['location_id' => $this->world->id('location:mekanikomor-binan'), 'lines' => [['item_id' => $wipers, 'quantity' => '1', 'unit_cost_cents' => 100]]])->assertUnprocessable();
    $this->postJson('/api/v1/stock/opening', ['location_id' => $store, 'lines' => [['item_id' => $this->world->id('item:CAM-BLT-A'), 'quantity' => '1', 'unit_cost_cents' => 100], ['item_id' => $this->world->id('item:CAM-BLT-A'), 'quantity' => '1', 'unit_cost_cents' => 100]]])->assertUnprocessable();
    $this->postJson('/api/v1/stock/opening', ['location_id' => $store, 'lines' => [['item_id' => $this->world->id('item:SVC-DIAG'), 'quantity' => '1', 'unit_cost_cents' => 100]]])->assertUnprocessable();
    $this->postJson('/api/v1/stock/opening', ['location_id' => $store, 'lines' => [['item_id' => $this->world->id('item:CAM-BLT-A'), 'quantity' => '0', 'unit_cost_cents' => 100]]])->assertUnprocessable();

    // A branch manager opens their own branch's stock, not another's; an advisor opens nobody's.
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $this->postJson('/api/v1/stock/opening', ['location_id' => $this->world->id('location:mekanikomor-binan'), 'lines' => [['item_id' => $this->world->id('item:CAM-BLT-A'), 'quantity' => '1', 'unit_cost_cents' => 100]]])->assertNotFound();
    $this->postJson('/api/v1/stock/opening', ['location_id' => $store, 'lines' => [['item_id' => $this->world->id('item:CAM-BLT-A'), 'quantity' => '2', 'unit_cost_cents' => 5500]]])->assertCreated();
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $this->postJson('/api/v1/stock/opening', ['location_id' => $store, 'lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'quantity' => '2', 'unit_cost_cents' => 100]]])->assertForbidden();
    $this->postJson('/api/v1/stock/opening', ['location_id' => $this->world->id('rival:location'), 'lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'quantity' => '2', 'unit_cost_cents' => 100]]])->assertNotFound();
});

it('pages the movements newest first and filters them', function () {
    $oil = $this->world->id('item:90915-YZZD4');

    $moves = $this->getJson("/api/v1/stock/moves?item_id={$oil}")->assertOk()->json('data');
    expect(array_column($moves, 'move_type'))->toBe(['receipt', 'opening'])
        ->and(array_column($moves, 'quantity'))->toBe(['10.000', '3.000'])
        ->and($moves[0]['source_reference'])->toBe('GR-2026-0001')
        ->and($moves[1]['source_reference'])->toBeNull()
        ->and($moves[1]['source_type'])->toBe('manual')
        ->and($moves[1]['reason'])->toBe('Opening balance');

    // A cursor, not a page number: it only grows.
    $first = $this->getJson("/api/v1/stock/moves?branch_id={$this->repair}&per_page=4")->assertOk();
    expect($first->json('data'))->toHaveCount(4)->and($first->json('meta.next_cursor'))->not->toBeNull();
    $second = $this->getJson("/api/v1/stock/moves?branch_id={$this->repair}&per_page=4&cursor=".urlencode($first->json('meta.next_cursor')))->assertOk();
    expect(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')))->toBe([]);

    $types = fn (string $query): array => array_unique(array_column($this->getJson("/api/v1/stock/moves?branch_id={$this->repair}{$query}")->assertOk()->json('data'), 'move_type'));
    expect($types('&move_type=adjustment'))->toBe([0 => 'adjustment'])
        ->and($types('&source_type=stock_transfer'))->toBe([0 => 'transfer_in'])
        ->and($this->getJson('/api/v1/stock/moves?from=2026-10-08&to=2026-10-08')->assertOk()->json('data'))->not->toBeEmpty()
        ->and($this->getJson('/api/v1/stock/moves?from=2026-10-09')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/stock/moves?to=2026-10-07')->assertOk()->json('data'))->toBe([]);
    $this->getJson('/api/v1/stock/moves?from=2026-10-09&to=2026-10-08')->assertUnprocessable();
    $this->getJson('/api/v1/stock/moves?move_type=nonsense')->assertUnprocessable();
    // The detailing branch's ledger is not the repair manager's... and the rival's is nobody's.
    expect($this->getJson('/api/v1/stock/moves?location_id='.$this->world->id('rival:location'))->assertOk()->json('data'))->toBe([]);
});

it('derives low-stock alerts on read, worst first, and stores nothing', function () {
    $alerts = $this->getJson('/api/v1/stock/alerts')->assertOk();
    $camber = $this->world->id('item:CAM-BLT-A');
    $air = $this->world->id('item:17801-0L040');
    $store = $this->world->id('location:mekanikomor-binan');

    expect(array_column($alerts->json('data'), 'id'))->toBe(["stock:{$camber}:{$store}", "stock:{$air}:{$store}"])
        ->and(array_column($alerts->json('data'), 'severity'))->toBe(['critical', 'warning'])
        ->and($alerts->json('data.0.message'))->toBe('Camber adjustment bolt is out of stock at MekanikoMoR-Biñan store (reorder point 6).')
        ->and($alerts->json('data.1.message'))->toBe('Engine air filter element is low at MekanikoMoR-Biñan store: 6 pc on hand against a reorder point of 6.')
        ->and($alerts->json('data.1.on_hand'))->toBe('6.000')
        ->and($alerts->json('meta'))->toBe(['total' => 2, 'critical' => 1]);

    // Eight oil filters go to the detailing branch: 5 left against a point of 8.
    $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $store, 'to_location_id' => $this->world->id('location:samahuzai-binan'), 'lines' => [['item_id' => $this->world->id('item:90915-YZZD4'), 'quantity' => 8]]])->assertCreated();
    $skus = array_column(array_column($this->getJson('/api/v1/stock/alerts')->json('data'), 'item'), 'sku');
    expect($skus[0])->toBe('CAM-BLT-A')
        ->and($skus)->toEqualCanonicalizing(['CAM-BLT-A', '17801-0L040', '90915-YZZD4']);
    // The detailing branch has nothing low of its own: the alerts are each branch's.
    expect($this->getJson("/api/v1/stock/alerts?branch_id={$this->detailing}")->json('data'))->toBe([]);

    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    expect($this->getJson('/api/v1/stock/alerts')->json('data'))->toBe([]);
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $this->getJson('/api/v1/stock/alerts')->assertForbidden();
});

it('advises what to reorder from on hand, on order, the reorder points and the fleet forecast', function () {
    $camber = $this->world->id('item:CAM-BLT-A');

    $response = $this->getJson("/api/v1/stock/reorder?branch_id={$this->repair}")->assertOk();
    $rows = $response->json('data');
    expect(array_column(array_column($rows, 'item'), 'sku'))->toBe(['CAM-BLT-A'])
        ->and($rows[0])->toMatchArray([
            'branch_id' => $this->repair,
            'on_hand' => '0.000',
            'on_order' => '0.000',
            // Actimed's 6-week forecast is short one camber bolt (SKU CAM-BLT-A).
            'forecast_shortfall' => '1.000',
            'reorder_point' => '6.000',
            'reorder_qty' => '12.000',
            'projected' => '-1.000',
            'reason' => 'stockout',
            'needs_order' => true,
            'suggested_stock_quantity' => '12.000',
            'suggested_purchase_quantity' => '12.000',
        ])
        ->and($response->json('meta'))->toBe(['total' => 1, 'needing_order' => 1]);

    // Everything, with the reasoning for the rows that are fine: the oil filter is covered by
    // 20 pieces still on order, and the forecast's 4 do not dent it.
    $all = collect($this->getJson("/api/v1/stock/reorder?branch_id={$this->repair}&all=1")->json('data'))->keyBy('item.sku');
    expect($all)->toHaveCount(10)
        ->and($all['90915-YZZD4'])->toMatchArray(['on_hand' => '13.000', 'on_order' => '20.000', 'forecast_shortfall' => '4.000', 'projected' => '29.000', 'reason' => 'ok', 'needs_order' => false])
        ->and($all['90915-YZZD4']['item']['purchase_uom'])->toBe('box')
        ->and($all['90915-YZZD4']['item']['purchase_uom_factor'])->toBe('10.000')
        ->and($all['90915-YZZD4']['item']['preferred_vendor_name'])->toBe('Toyota Shaw Service Center')
        ->and($all['SVC-DIAG'] ?? null)->toBeNull();

    // Buy the bolts: an issued order covers the stockout (the row stays, with nothing more to buy).
    $vendor = asSystem(fn () => Vendor::query()->where('name', 'Bridgestone Tire Center')->firstOrFail()->id);
    $po = $this->postJson('/api/v1/shop-purchase-orders', ['branch_id' => $this->repair, 'vendor_id' => $vendor, 'lines' => [['item_id' => $camber, 'quantity' => 12, 'unit_cost_cents' => 34000]]])->assertCreated()->json('data.id');
    // A draft is not yet on order.
    expect($this->getJson("/api/v1/stock/reorder?branch_id={$this->repair}")->json('data.0.on_order'))->toBe('0.000');
    $this->postJson("/api/v1/shop-purchase-orders/{$po}/issue")->assertOk();
    $row = $this->getJson("/api/v1/stock/reorder?branch_id={$this->repair}")->json('data.0');
    expect($row['on_order'])->toBe('12.000')->and($row['needs_order'])->toBeFalse()->and($row['suggested_stock_quantity'])->toBe('0.000');

    // Without the forecast (a 1-week horizon, a named account that has none), only the shelf speaks.
    $this->getJson("/api/v1/stock/reorder?branch_id={$this->repair}&all=1&customer_account_id={$this->world->id('fc-sagrada')}")->assertOk()
        ->assertJsonPath('data.0.forecast_shortfall', '0.000');
    $this->getJson('/api/v1/stock/reorder?horizon_weeks=0')->assertUnprocessable();

    Sanctum::actingAs($this->world->user('cashier@mekanikomore.ph'));
    $this->getJson('/api/v1/stock/reorder')->assertOk();
    Sanctum::actingAs($this->world->user('purchasing@mekanikomor.ph'));
    $this->getJson('/api/v1/stock/reorder')->assertForbidden();
});
