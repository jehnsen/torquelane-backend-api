<?php

declare(strict_types=1);

use App\Models\StockCount;
use App\Models\StockMove;
use App\Models\StockTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Stock counts (a sheet, counted quantities, adjustments with a reason) and
 * inter-branch transfers (one document, both moves). Seed: SC-2026-0001
 * found a litre of coolant missing at the repair store (25 → 24); TR-2026-0001
 * moved 10 microfibre cloths from the detailing branch to the repair shop.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->repair = $this->world->id('mekanikomor-binan');
    $this->detailing = $this->world->id('samahuzai-binan');
    $this->repairStore = $this->world->id('location:mekanikomor-binan');
    $this->detailingStore = $this->world->id('location:samahuzai-binan');
});

function ctSignIn(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/** On hand and average cost of an item at a branch, from the items API. */
function stockOf(string $sku, string $branchId): array
{
    $branch = collect(test()->getJson('/api/v1/items/'.test()->world->id("item:{$sku}"))->json('data.branches'))->firstWhere('branch_id', $branchId);

    return ['on_hand' => $branch['on_hand'], 'avg_cost_cents' => $branch['avg_cost_cents']];
}

// ------------------------------------------------------------------ counts

it('shows the seeded count as posted, with the adjustment it made', function () {
    ctSignIn('owner@mekanikomore.ph');

    $count = $this->getJson('/api/v1/stock-counts/'.$this->world->id('stock-count:cycle'))->assertOk()->json('data');
    $coolant = collect($count['lines'])->firstWhere('item.sku', '08889-80015');

    expect($count['reference'])->toBe('SC-2026-0001')
        ->and($count['status'])->toBe('posted')
        ->and($count['can_edit'])->toBeFalse()
        ->and($coolant['expected_quantity'])->toBe('25.000')
        ->and($coolant['counted_quantity'])->toBe('24.000')
        ->and($coolant['variance_quantity'])->toBe('-1.000')
        ->and($coolant['unit_cost_cents'])->toBe(52000)
        ->and($coolant['variance_value_cents'])->toBe(-52000)
        ->and($coolant['reason'])->toBe('Spillage')
        ->and($count['summary'])->toBe(['lines' => 8, 'counted_lines' => 2, 'variance_lines' => 1, 'net_variance_value_cents' => -52000]);

    $moves = $this->getJson('/api/v1/stock/moves?move_type=adjustment')->json('data');
    expect($moves)->toHaveCount(1)
        ->and($moves[0]['quantity'])->toBe('-1.000')
        ->and($moves[0]['reason'])->toBe('SC-2026-0001: Spillage')
        ->and($moves[0]['source_reference'])->toBe('SC-2026-0001');
});

it('draws a sheet from the books, takes the counts, and posts adjustments with a reason', function () {
    ctSignIn('owner@mekanikomore.ph');
    $air = $this->world->id('item:17801-0L040');
    $oil = $this->world->id('item:HX7-5W30-1L');

    $sheet = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->repairStore, 'item_ids' => [$air]])->assertCreated();
    $sheet->assertJsonPath('data.reference', 'SC-2026-0002')->assertJsonPath('data.status', 'open')->assertJsonPath('data.can_edit', true);
    $id = $sheet->json('data.id');
    $lines = collect($sheet->json('data.lines'))->keyBy('item.sku');
    expect($lines['17801-0L040']['expected_quantity'])->toBe('6.000')
        ->and($lines['17801-0L040']['counted_quantity'])->toBeNull()
        // Every item the store holds is on the sheet.
        ->and($lines->keys()->sort()->values()->all())->toBe(['04465-0K340', '08889-80015', '17801-0L040', '90915-YZZD4', 'DTL-MF-CLOTH', 'HX7-5W30-1L', 'RAG-SHOP', 'WPR-BLD-22']);

    // Five air filters where the books say six; oil exactly as booked; nothing said of the rest.
    $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [
        ['item_id' => $air, 'counted_quantity' => '5'],
        ['item_id' => $oil, 'counted_quantity' => '60'],
    ]])->assertOk()->assertJsonPath('data.summary.counted_lines', 2);

    // A variance with no reason, on the line or the sheet, is refused, and nothing moves.
    $this->postJson("/api/v1/stock-counts/{$id}/post")->assertUnprocessable();
    expect(stockOf('17801-0L040', $this->repair)['on_hand'])->toBe('6.000')
        ->and(asSystem(fn () => StockMove::query()->where('move_type', 'adjustment')->where('organization_id', $this->world->id('prov-mekanikomore'))->count()))->toBe(1);

    $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [['item_id' => $air, 'counted_quantity' => '5', 'reason' => 'Damaged in handling']]])->assertOk();
    $posted = $this->postJson("/api/v1/stock-counts/{$id}/post")->assertOk();
    $posted->assertJsonPath('data.status', 'posted')->assertJsonPath('data.can_edit', false)->assertJsonPath('data.summary.variance_lines', 1);

    $airLine = collect($posted->json('data.lines'))->firstWhere('item.sku', '17801-0L040');
    expect($airLine['variance_quantity'])->toBe('-1.000')
        ->and($airLine['unit_cost_cents'])->toBe(85000)
        ->and(collect($posted->json('data.lines'))->firstWhere('item.sku', 'HX7-5W30-1L')['variance_quantity'])->toBe('0.000')
        ->and(collect($posted->json('data.lines'))->firstWhere('item.sku', 'RAG-SHOP')['variance_quantity'])->toBeNull()
        ->and(stockOf('17801-0L040', $this->repair)['on_hand'])->toBe('5.000')
        // A count never changes what stock costs.
        ->and(stockOf('17801-0L040', $this->repair)['avg_cost_cents'])->toBe(85000);

    $adjustment = asSystem(fn () => StockMove::query()->where('source_id', $id)->get());
    expect($adjustment)->toHaveCount(1)
        ->and($adjustment[0]->reason)->toBe('SC-2026-0002: Damaged in handling');

    // A posted count is closed: nothing re-posts, edits or cancels it, in the API or the database.
    $this->postJson("/api/v1/stock-counts/{$id}/post")->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [['item_id' => $air, 'counted_quantity' => '9']]])->assertStatus(409);
    $this->postJson("/api/v1/stock-counts/{$id}/cancel")->assertStatus(409);
    expect(fn () => DB::transaction(fn () => DB::table('stock_count_lines')->where('stock_count_id', $id)->update(['counted_quantity' => 9])))->toThrow(QueryException::class, 'not edited')
        ->and(fn () => DB::transaction(fn () => DB::table('stock_counts')->where('id', $id)->delete()))->toThrow(QueryException::class, 'never deleted');
});

it('takes the sheet\'s reason for lines that give none, and adds an item found on the shelf', function () {
    ctSignIn('owner@mekanikomore.ph');
    $camber = $this->world->id('item:CAM-BLT-A');
    $wipers = $this->world->id('item:WPR-BLD-22');

    $id = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->repairStore, 'reason' => 'Annual count'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [
        ['item_id' => $wipers, 'counted_quantity' => '7'],
        // Never booked here, but there are four on the shelf: it joins the sheet at zero.
        ['item_id' => $camber, 'counted_quantity' => '4'],
    ]])->assertOk()->assertJsonPath('data.summary.lines', 9);

    $this->postJson("/api/v1/stock-counts/{$id}/post")->assertOk()->assertJsonPath('data.summary.variance_lines', 2);

    expect(stockOf('WPR-BLD-22', $this->repair)['on_hand'])->toBe('7.000')
        ->and(stockOf('CAM-BLT-A', $this->repair))->toBe(['on_hand' => '4.000', 'avg_cost_cents' => 0]);
    $reasons = asSystem(fn () => StockMove::query()->where('source_id', $id)->pluck('reason')->sort()->values()->all());
    expect($reasons)->toBe(['SC-2026-0002: Annual count', 'SC-2026-0002: Annual count']);
});

it('measures the variance against what the books hold when the count is posted', function () {
    ctSignIn('owner@mekanikomore.ph');
    $rags = $this->world->id('item:RAG-SHOP');

    $id = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->repairStore, 'item_ids' => [$rags], 'reason' => 'Cycle count'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [['item_id' => $rags, 'counted_quantity' => '9']]])->assertOk();

    // While the sheet was out, a bag of rags went to the detailing branch.
    $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $this->repairStore, 'to_location_id' => $this->detailingStore, 'lines' => [['item_id' => $rags, 'quantity' => '1']]])->assertCreated();

    $posted = $this->postJson("/api/v1/stock-counts/{$id}/post")->assertOk();
    $line = collect($posted->json('data.lines'))->firstWhere('item.sku', 'RAG-SHOP');
    expect($line['expected_quantity'])->toBe('9.000')
        ->and($line['variance_quantity'])->toBe('0.000')
        ->and(stockOf('RAG-SHOP', $this->repair)['on_hand'])->toBe('9.000');
});

it('cancels an open sheet without moving anything, and refuses to post an empty one', function () {
    ctSignIn('owner@mekanikomore.ph');

    $id = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->repairStore])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/stock-counts/{$id}/post")->assertUnprocessable();
    $this->postJson("/api/v1/stock-counts/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->putJson("/api/v1/stock-counts/{$id}/lines", ['lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'counted_quantity' => '1']]])->assertStatus(409);

    expect(asSystem(fn () => StockCount::query()->where('status', 'cancelled')->where('organization_id', $this->world->id('prov-mekanikomore'))->count()))->toBe(1)
        ->and(asSystem(fn () => StockMove::query()->where('move_type', 'adjustment')->where('organization_id', $this->world->id('prov-mekanikomore'))->count()))->toBe(1);

    // Nothing is held at a store with no stock, and no item named.
    $this->postJson('/api/v1/stock-counts', ['location_id' => $this->world->id('rival:location')])->assertNotFound();
});

it('lets only those who manage stock in that branch count it', function () {
    ctSignIn('manager.samahuzai@mekanikomore.ph');
    $this->postJson('/api/v1/stock-counts', ['location_id' => $this->repairStore])->assertNotFound();
    $mine = $this->postJson('/api/v1/stock-counts', ['location_id' => $this->detailingStore])->assertCreated()->json('data.id');

    ctSignIn('advisor@mekanikomore.ph');
    $this->postJson('/api/v1/stock-counts', ['location_id' => $this->repairStore])->assertForbidden();
    $this->getJson("/api/v1/stock-counts/{$mine}")->assertOk();
    $this->postJson("/api/v1/stock-counts/{$mine}/post")->assertForbidden();

    // The counter is pinned to the repair branch: the detailing branch's count is not theirs to see.
    ctSignIn('cashier@mekanikomore.ph');
    $this->getJson("/api/v1/stock-counts/{$mine}")->assertNotFound();

    ctSignIn('donmiguel@mekanikomor.ph');
    $this->getJson('/api/v1/stock-counts')->assertForbidden();
});

// --------------------------------------------------------------- transfers

it('shows the seeded transfer: one document, both moves, the cost carried across', function () {
    ctSignIn('owner@mekanikomore.ph');

    $transfer = $this->getJson('/api/v1/stock-transfers/'.$this->world->id('stock-transfer:cloths'))->assertOk()->json('data');

    expect($transfer['reference'])->toBe('TR-2026-0001')
        ->and($transfer['from_branch_id'])->toBe($this->detailing)
        ->and($transfer['to_branch_id'])->toBe($this->repair)
        ->and($transfer['lines'][0]['quantity'])->toBe('10.000')
        ->and($transfer['lines'][0]['unit_cost_cents'])->toBe(4500)
        ->and($transfer['total_value_cents'])->toBe(45000)
        ->and($transfer['can_reverse'])->toBeTrue();

    // 30 − 10 left the detailing store; 10 came into the repair store, at the same cost.
    expect(stockOf('DTL-MF-CLOTH', $this->detailing))->toBe(['on_hand' => '20.000', 'avg_cost_cents' => 4500])
        ->and(stockOf('DTL-MF-CLOTH', $this->repair))->toBe(['on_hand' => '10.000', 'avg_cost_cents' => 4500]);

    $moves = collect($this->getJson('/api/v1/stock/moves?source_type=stock_transfer')->json('data'));
    expect($moves->pluck('move_type')->sort()->values()->all())->toBe(['transfer_in', 'transfer_out'])
        ->and($moves->pluck('source_reference')->unique()->all())->toBe(['TR-2026-0001'])
        ->and($moves->pluck('unit_cost_cents')->unique()->all())->toBe([4500]);
});

it('transfers goods at the source\'s average cost and blends them into the destination\'s', function () {
    ctSignIn('owner@mekanikomore.ph');
    $coolant = $this->world->id('item:08889-80015');

    // The detailing store has never held coolant; the repair store has 24 at ₱520.
    $response = $this->postJson('/api/v1/stock-transfers', [
        'from_location_id' => $this->repairStore,
        'to_location_id' => $this->detailingStore,
        'notes' => 'Coolant for the detailing floor',
        'lines' => [['item_id' => $coolant, 'quantity' => '4.5']],
    ])->assertCreated();

    $response->assertJsonPath('data.reference', 'TR-2026-0002')
        ->assertJsonPath('data.lines.0.unit_cost_cents', 52000)
        ->assertJsonPath('data.total_value_cents', 234000)
        ->assertJsonPath('data.created_by_name', 'Mike Manabat');

    expect(stockOf('08889-80015', $this->repair))->toBe(['on_hand' => '19.500', 'avg_cost_cents' => 52000])
        ->and(stockOf('08889-80015', $this->detailing))->toBe(['on_hand' => '4.500', 'avg_cost_cents' => 52000]);

    // Into a store that already holds some: the average blends, the source's does not move.
    $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $this->detailingStore, 'to_location_id' => $this->repairStore, 'lines' => [['item_id' => $this->world->id('item:DTL-MF-CLOTH'), 'quantity' => '5']]])->assertCreated();
    expect(stockOf('DTL-MF-CLOTH', $this->repair))->toBe(['on_hand' => '15.000', 'avg_cost_cents' => 4500]);
});

it('refuses a bad transfer and leaves no trace, not even a used number', function () {
    ctSignIn('owner@mekanikomore.ph');
    $coolant = $this->world->id('item:08889-80015');
    $base = ['from_location_id' => $this->repairStore, 'to_location_id' => $this->detailingStore];

    $this->postJson('/api/v1/stock-transfers', [...$base, 'to_location_id' => $this->repairStore, 'lines' => [['item_id' => $coolant, 'quantity' => 1]]])->assertUnprocessable();
    $this->postJson('/api/v1/stock-transfers', [...$base, 'lines' => []])->assertUnprocessable();
    $this->postJson('/api/v1/stock-transfers', [...$base, 'lines' => [['item_id' => $coolant, 'quantity' => 1], ['item_id' => $coolant, 'quantity' => 1]]])->assertUnprocessable();
    $this->postJson('/api/v1/stock-transfers', [...$base, 'lines' => [['item_id' => $this->world->id('item:SVC-DIAG'), 'quantity' => 1]]])->assertUnprocessable();
    $this->postJson('/api/v1/stock-transfers', [...$base, 'to_location_id' => $this->world->id('rival:location'), 'lines' => [['item_id' => $coolant, 'quantity' => 1]]])->assertNotFound();

    $movesBefore = asSystem(fn () => StockMove::query()->count());
    $transfersBefore = asSystem(fn () => StockTransfer::query()->count());

    // Blocking branch: more than is there is refused whole.
    $this->patchJson("/api/v1/branches/{$this->repair}", ['negative_stock_policy' => 'block'])->assertOk()->assertJsonPath('data.negative_stock_policy', 'block');
    $this->postJson('/api/v1/stock-transfers', [...$base, 'lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'quantity' => 1], ['item_id' => $coolant, 'quantity' => 24.001]]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.details.reason', 'insufficient_stock')
        ->assertJsonPath('error.details.on_hand', '24')
        ->assertJsonPath('error.details.requested', '24.001');

    expect(asSystem(fn () => StockMove::query()->count()))->toBe($movesBefore)
        ->and(asSystem(fn () => StockTransfer::query()->count()))->toBe($transfersBefore)
        ->and(stockOf('RAG-SHOP', $this->repair)['on_hand'])->toBe('10.000');

    // The failed attempts did not use up TR-2026-0002 (gap-free numbering, R8).
    $this->postJson('/api/v1/stock-transfers', [...$base, 'lines' => [['item_id' => $coolant, 'quantity' => 24]]])->assertCreated()->assertJsonPath('data.reference', 'TR-2026-0002');
    expect(stockOf('08889-80015', $this->repair)['on_hand'])->toBe('0.000');
});

it('allows and flags a negative balance where the branch says so', function () {
    ctSignIn('owner@mekanikomore.ph');
    $rags = $this->world->id('item:RAG-SHOP');

    $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $this->repairStore, 'to_location_id' => $this->detailingStore, 'lines' => [['item_id' => $rags, 'quantity' => 12]]])->assertCreated();
    expect(stockOf('RAG-SHOP', $this->repair)['on_hand'])->toBe('-2.000');

    $out = collect($this->getJson("/api/v1/stock/moves?item_id={$rags}&move_type=transfer_out")->json('data'))->first();
    expect($out['negative_flag'])->toBeTrue();
    // The balance shows it too.
    $row = collect($this->getJson('/api/v1/stock/on-hand?q=RAG')->json('data'))->first();
    expect($row['is_negative'])->toBeTrue()->and($this->getJson('/api/v1/stock/on-hand')->json('summary.negative'))->toBe(1);
});

it('reverses a transfer once, as a new document that names it', function () {
    ctSignIn('owner@mekanikomore.ph');
    $original = $this->world->id('stock-transfer:cloths');

    $reversal = $this->postJson("/api/v1/stock-transfers/{$original}/reverse")->assertCreated();
    $reversal->assertJsonPath('data.reference', 'TR-2026-0002')
        ->assertJsonPath('data.reverses_transfer_id', $original)
        ->assertJsonPath('data.from_branch_id', $this->repair)
        ->assertJsonPath('data.to_branch_id', $this->detailing)
        ->assertJsonPath('data.can_reverse', false);

    expect(stockOf('DTL-MF-CLOTH', $this->detailing)['on_hand'])->toBe('30.000')
        ->and(stockOf('DTL-MF-CLOTH', $this->repair)['on_hand'])->toBe('0.000');

    $this->getJson("/api/v1/stock-transfers/{$original}")->assertJsonPath('data.reversed_by_transfer_id', $reversal->json('data.id'))->assertJsonPath('data.can_reverse', false);
    $this->postJson("/api/v1/stock-transfers/{$original}/reverse")->assertStatus(409)->assertJsonPath('error.code', 'conflict');
    $this->postJson('/api/v1/stock-transfers/'.$reversal->json('data.id').'/reverse')->assertStatus(409);

    // The documents are immutable in the database too.
    expect(fn () => DB::transaction(fn () => DB::table('stock_transfers')->where('id', $original)->update(['notes' => 'x'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('stock_transfer_lines')->where('stock_transfer_id', $original)->delete()))->toThrow(QueryException::class, 'append-only');
});

it('shows a transfer to staff of either branch, and makes it from the branch the goods leave', function () {
    $cloths = $this->world->id('stock-transfer:cloths');

    // The detailing branch manager sent it; the repair counter received it. Both see it.
    ctSignIn('manager.samahuzai@mekanikomore.ph');
    $this->getJson("/api/v1/stock-transfers/{$cloths}")->assertOk();
    $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $this->repairStore, 'to_location_id' => $this->detailingStore, 'lines' => [['item_id' => $this->world->id('item:RAG-SHOP'), 'quantity' => 1]]])->assertNotFound();
    // Reversing means taking the goods back out of the repair store: not theirs to do.
    $this->postJson("/api/v1/stock-transfers/{$cloths}/reverse")->assertNotFound();

    ctSignIn('cashier@mekanikomore.ph');
    $this->getJson("/api/v1/stock-transfers/{$cloths}")->assertOk();
    $this->postJson("/api/v1/stock-transfers/{$cloths}/reverse")->assertForbidden();
    expect($this->getJson('/api/v1/stock-transfers')->json('meta.total'))->toBe(1);
});
