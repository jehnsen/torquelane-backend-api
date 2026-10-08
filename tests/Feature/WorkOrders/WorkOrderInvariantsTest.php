<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\DocumentSeries;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use App\Models\WorkOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The invariants Phase 3 promises, each against the real database:
 *
 *  - two accounts under one organization cannot produce colliding references;
 *  - totals a client sends are ignored (the server prices every line);
 *  - a draft burns no number;
 *  - a portal user cannot raise an order on a sibling account's vehicle;
 *  - a failure mid-action leaves no partial rows;
 *
 * plus the database's own guards: an approved line's price, the append-only
 * history and log, and the reference index.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
});

/** A one-line draft, over the auto-approve ceiling so it waits on the customer. */
function draftFor(string $vehicle): string
{
    return test()->postJson('/api/v1/work-orders', [
        'vehicle_id' => $vehicle,
        'title' => 'Brake service',
        'type' => 'corrective',
        'lines' => [['description' => 'Brake pads', 'quantity' => 1, 'unit_part_rate_cents' => 900000]],
    ])->assertCreated()->json('data.id');
}

/**
 * A statement expected to fail, in its own savepoint: Postgres aborts the
 * surrounding (test) transaction on any error otherwise.
 */
function refusedByDatabase(Closure $statement): Closure
{
    return fn () => DB::transaction(fn () => asSystem($statement));
}

function nextWorkOrderNumber(): int
{
    return asSystem(fn (): int => DocumentSeries::query()->where('doc_type', 'work_order')->where('period_key', '2026')->whereNull('branch_id')
        ->where('organization_id', test()->world->id('prov-mekanikomore'))->value('next_number'));
}

it('numbers orders for two accounts from one series, never colliding', function () {
    $actimed = draftFor($this->world->id('veh-001'));
    $northwind = draftFor($this->world->id('veh-101'));

    $first = $this->postJson("/api/v1/work-orders/{$northwind}/send")->assertOk()->json('data.reference');
    $second = $this->postJson("/api/v1/work-orders/{$actimed}/send")->assertOk()->json('data.reference');

    expect([$first, $second])->toBe(['WO-2026-1657', 'WO-2026-1658'])
        ->and(nextWorkOrderNumber())->toBe(1659);

    // The index stands behind the series: a duplicate number in one organization cannot be written.
    expect(refusedByDatabase(fn () => WorkOrder::query()->whereKey($actimed)->update(['reference' => $first])))
        ->toThrow(QueryException::class);
});

it('ignores every cost and total a client sends', function () {
    $response = $this->postJson('/api/v1/work-orders', [
        'vehicle_id' => $this->world->id('veh-001'),
        'title' => 'Tampered',
        'type' => 'corrective',
        'labor_cost_cents' => 1,
        'parts_cost_cents' => 1,
        'totals' => ['grand_total_cents' => 1],
        'lines' => [[
            'description' => 'Brake pads',
            'quantity' => 2,
            'unit_part_rate_cents' => 180000,
            'labour_hours' => 1,
            'labour_rate_cents' => 65000,
            'part_cost_cents' => 1,
            'labour_cost_cents' => 1,
            'line_cost_cents' => 1,
        ]],
    ])->assertCreated();

    $response->assertJsonPath('data.lines.0.part_cost_cents', 360000)
        ->assertJsonPath('data.lines.0.labour_cost_cents', 65000)
        ->assertJsonPath('data.labor_cost_cents', 65000)
        ->assertJsonPath('data.parts_cost_cents', 360000)
        ->assertJsonPath('data.totals.grand_total_cents', 476000);

    $id = $response->json('data.id');
    $this->putJson("/api/v1/work-orders/{$id}/lines", ['lines' => [['description' => 'Pads', 'quantity' => 1, 'unit_part_rate_cents' => 100, 'part_cost_cents' => 999999]]])
        ->assertOk()->assertJsonPath('data.lines.0.part_cost_cents', 100);
});

it('burns no number for a draft', function () {
    $abandoned = draftFor($this->world->id('veh-001'));
    $this->postJson("/api/v1/work-orders/{$abandoned}/cancel", ['reason' => 'Walked out.'])->assertOk()->assertJsonPath('data.reference', '');
    expect(nextWorkOrderNumber())->toBe(1657);

    $sent = draftFor($this->world->id('veh-001'));
    $this->postJson("/api/v1/work-orders/{$sent}/send")->assertOk()->assertJsonPath('data.reference', 'WO-2026-1657');
});

it('will not let a portal user raise work on a sibling account\'s vehicle, or see its orders', function () {
    $northwindOrder = draftFor($this->world->id('veh-101'));
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $this->postJson('/api/v1/work-orders', [
        'vehicle_id' => $this->world->id('veh-101'),
        'title' => 'Not mine',
        'type' => 'corrective',
    ])->assertNotFound();
    $this->getJson("/api/v1/work-orders/{$northwindOrder}")->assertNotFound();
    $this->postJson("/api/v1/work-orders/{$northwindOrder}/send")->assertNotFound();

    $accounts = array_unique(array_column($this->getJson('/api/v1/work-orders?per_page=100')->assertOk()->json('data'), 'customer_account_id'));
    expect(array_values($accounts))->toBe([$this->world->id('fc-actimed')]);
});

it('will not let a suspended account take new work', function () {
    $bayani = asSystem(fn () => DB::table('vehicles')->where('customer_account_id', $this->world->id('fc-bayani'))->value('id'));

    $this->postJson('/api/v1/work-orders', ['vehicle_id' => $bayani, 'title' => 'x', 'type' => 'corrective'])
        ->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
});

it('leaves no partial rows when an action fails midway', function () {
    $id = draftFor($this->world->id('veh-001'));
    $events = asSystem(fn () => WorkOrderEvent::query()->count());
    $audits = asSystem(fn () => AuditLog::query()->count());

    // The approval log is the last write sendForApproval makes before its
    // event and audit row: refuse it, after the order, lines and number are written.
    DB::unprepared(<<<'SQL'
        create function test_refuse_log() returns trigger language plpgsql as $$
        begin raise exception 'simulated failure'; end; $$;
        create trigger test_refuse_log before insert on approval_log for each row execute function test_refuse_log();
        SQL);

    $this->postJson("/api/v1/work-orders/{$id}/send")->assertStatus(500)->assertJsonPath('error.code', 'server_error');

    DB::unprepared('drop trigger test_refuse_log on approval_log; drop function test_refuse_log();');

    $order = asSystem(fn () => WorkOrder::query()->with('lines')->findOrFail($id));
    expect($order->status->value)->toBe('draft')
        ->and($order->reference)->toBe('')
        ->and($order->pending_approval_entered_at)->toBeNull()
        ->and($order->lines->pluck('approval_status')->map->value->all())->toBe(['pending'])
        ->and(asSystem(fn () => WorkOrderEvent::query()->count()))->toBe($events)
        ->and(asSystem(fn () => AuditLog::query()->count()))->toBe($audits)
        // The number the failed send drew went back with the rollback.
        ->and(nextWorkOrderNumber())->toBe(1657);

    $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk()->assertJsonPath('data.reference', 'WO-2026-1657');
});

it('refuses, in the database, to re-price or delete an approved line', function () {
    $line = asSystem(fn () => WorkOrderLine::query()->where('approval_status', 'approved')->firstOrFail());

    expect(refusedByDatabase(fn () => WorkOrderLine::query()->whereKey($line->id)->update(['unit_part_rate_cents' => 1, 'part_cost_cents' => 1])))
        ->toThrow(QueryException::class, 'cannot be re-priced')
        ->and(refusedByDatabase(fn () => WorkOrderLine::query()->whereKey($line->id)->delete()))
        ->toThrow(QueryException::class);
});

it('holds a stored cost to its quantity and rate', function () {
    $line = asSystem(fn () => WorkOrderLine::query()->where('approval_status', 'pending')->firstOrFail());

    expect(refusedByDatabase(fn () => WorkOrderLine::query()->whereKey($line->id)->update(['part_cost_cents' => $line->part_cost_cents + 1])))
        ->toThrow(QueryException::class, 'work_order_lines_cost_check');
});

it('keeps the status history and approval log append-only', function (string $table) {
    $id = asSystem(fn () => DB::table($table)->value('id'));

    expect(refusedByDatabase(fn () => DB::table($table)->where('id', $id)->update(['actor_name' => 'Someone else'])))->toThrow(QueryException::class)
        ->and(refusedByDatabase(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(QueryException::class);
})->with(['work_order_events', 'approval_log']);

it('allows a reference only once the order has left draft', function () {
    $id = draftFor($this->world->id('veh-001'));

    expect(refusedByDatabase(fn () => WorkOrder::query()->whereKey($id)->update(['status' => 'pending_approval'])))
        ->toThrow(QueryException::class, 'work_orders_reference_check');
});
