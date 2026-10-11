<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Equipment monitoring at Samahuzai-Biñan over HTTP. The engine itself is held
 * to the source's recording (tests/Unit/Domain/Equipment); here the point is
 * that the API feeds it from what is on record: a tool's meters come from
 * completed detailing jobs (through its package profiles) and from its usage
 * log, are never stored, and re-settle when either changes.
 *
 * Seed: the source's 22 tools and the lounge's espresso machine. The
 * compressor has 420 h on the clock, a 500 h interval, a service 60 days ago
 * (₱1,850, 2.5 h down) and a 6.5 h run logged last week; it cost ₱85,000. The
 * espresso machine is metered in cups: 4,150 of 5,000 logged since it was
 * registered. The store holds 20 microfibre cloths at ₱45 and 4 jugs of
 * shampoo at ₱850.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T08:30:00+08:00'));
    $this->world = World::build();
    $this->essential = $this->world->id('detailing:package:essential');
});

function equipAs(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

function tool(string $id): array
{
    return test()->getJson("/api/v1/equipment/{$id}")->assertOk()->json('data');
}

/** Take The Essential (medium) for the walk-in and finish it at `$doneAt`. */
function finishAnEssential(string $date, string $start, string $doneAt): string
{
    $id = test()->postJson('/api/v1/detailing/bookings', [
        'customer_account_id' => test()->world->id('walk-in'),
        'package_id' => test()->essential,
        'size_class' => 'medium',
        'date' => $date,
        'start_time' => $start,
        'status' => 'confirmed',
    ])->assertCreated()->json('data.id');
    test()->travelTo(CarbonImmutable::parse($doneAt)->subHours(2));
    test()->postJson("/api/v1/detailing/bookings/{$id}/status", ['status' => 'on_going', 'inspection_override_reason' => 'Regular customer'])->assertOk();
    test()->travelTo(CarbonImmutable::parse($doneAt));
    test()->postJson("/api/v1/detailing/bookings/{$id}/status", ['status' => 'completed'])->assertOk();

    return $id;
}

it('shows the board: the source\'s 22 tools and the espresso machine, each with its report, and the floor in numbers', function () {
    equipAs('manager.samahuzai@mekanikomore.ph');

    $board = $this->getJson('/api/v1/equipment')->assertOk()->json('data');
    $compressor = collect($board['tools'])->firstWhere('name', 'Air Compressor (belt-drive)');
    $gauge = collect($board['tools'])->firstWhere('name', 'Paint Thickness Gauge');

    expect($board['tools'])->toHaveCount(23)
        ->and(array_column($board['tools'], 'name')[0])->toBe('High-Pressure Washer')
        ->and($board['summary'])->toMatchArray(['total' => 23, 'active' => 23, 'overdue' => 0, 'due_soon' => 1, 'down' => 0, 'untracked' => 0, 'asset_value_cents' => 8_500_000, 'maintenance_spend_cents' => 185_000])
        // 420 h on the clock + the 6.5 h logged; 6.5 h of 500 since the service.
        ->and($compressor['report']['lifetime'])->toEqual(['hours' => 426.5, 'cycles' => 0, 'jobs' => 0])
        ->and($compressor['report']['service'])->toMatchArray(['level' => 'ok', 'level_label' => 'OK', 'binding' => 'hours', 'progress' => 0.013, 'hours_since' => 6.5])
        ->and($compressor['report']['life'])->toMatchArray(['level' => 'healthy', 'ratio' => 426.5 / 8000])
        ->and($compressor['report']['reliability'])->toMatchArray(['services' => 1, 'repairs' => 0, 'maintenance_cost_cents' => 185_000, 'total_cost_cents' => 8_685_000, 'mtbf_hours' => null])
        // ₱86,850 over 426.5 h. At 6.5 h a month the 500 h service is over six years out.
        ->and($compressor['report']['reliability']['cost_per_hour_cents'])->toBe(20_363)
        ->and($compressor['report']['forecast']['due_on'])->toBe('2033-01-06')
        ->and($compressor['last_service_at'])->toBe('2026-08-13T00:30:00Z')
        // The plan, from the maintenance engine: serviced at 420 h, so the 500 h limit falls due at 920 h.
        ->and($compressor['report']['service']['triggers'])->toHaveCount(1)
        ->and($compressor['report']['service']['triggers'][0])->toMatchArray(['key' => 'hours', 'unit' => 'h', 'interval' => 500, 'due_at' => 920, 'due_on' => null])
        // A calendar tool: 365 days from the day it was registered.
        ->and($gauge['report']['service'])->toMatchArray(['binding' => 'days', 'level' => 'ok'])
        ->and($gauge['report']['service']['triggers'][0])->toMatchArray(['key' => 'days', 'due_at' => null, 'due_on' => '2027-10-12'])
        ->and($gauge['report']['forecast']['rate_per_day'])->toEqual(1);

    // The espresso machine wears in cups: 4,150 of 5,000, about a week left at 138 a day.
    $espresso = collect($board['tools'])->firstWhere('name', 'Espresso Machine');
    expect($espresso)->toMatchArray(['category' => 'cafe', 'category_label' => 'Café', 'meter_type' => 'cups', 'count_unit' => 'cups'])
        ->and($compressor['count_unit'])->toBe('cycles')
        ->and($espresso['report']['lifetime'])->toEqual(['hours' => 0, 'cycles' => 4150, 'jobs' => 0])
        ->and($espresso['report']['service'])->toMatchArray(['level' => 'due_soon', 'binding' => 'cups', 'progress' => 0.83, 'cycles_since' => 4150])
        ->and($espresso['report']['service']['triggers'][0])->toMatchArray(['key' => 'cups', 'unit' => 'cups', 'remaining' => 850, 'due_at' => 5000])
        ->and($espresso['report']['forecast']['due_on'])->toBe('2026-10-18');

    $options = $this->getJson('/api/v1/equipment/options')->assertOk()->json('data');
    expect(array_column($options['categories'], 'value'))->toBe(['wash', 'polishing', 'interior', 'coating', 'air', 'measurement', 'facility', 'cafe', 'other'])
        ->and(array_column($options['meter_types'], 'value'))->toBe(['hours', 'cycles', 'cups', 'days'])
        ->and($options['category_presets']['cafe'])->toBe(['meter_type' => 'cups', 'service_interval_cycles' => 5000, 'maintenance_task' => 'Descale and backflush'])
        ->and($options['category_presets']['polishing'])->toBe(['meter_type' => 'hours', 'service_interval_hours' => '180', 'expected_life_hours' => '1500', 'maintenance_task' => 'Backing plate + bearing / brush inspection'])
        ->and($options['thresholds'])->toBe(['due_soon_ratio' => 0.8, 'forecast_window_days' => 30, 'open_hours_per_day' => 10]);
});

it('derives a tool\'s meters from completed jobs and its usage log, and re-settles when either changes', function () {
    equipAs('manager.samahuzai@mekanikomore.ph');

    $made = $this->postJson('/api/v1/equipment', [
        'name' => 'Spot Extractor',
        'category' => 'interior',
        'brand' => 'Bissell',
        'service_interval_hours' => '2',
        'expected_life_hours' => '10',
        'baseline_hours' => '1.5',
        'purchase_cost_cents' => 1_200_000,
    ], ['Idempotency-Key' => 'tool-1'])->assertCreated()->json('data');
    $id = $made['id'];
    expect($made['report']['lifetime'])->toEqual(['hours' => 1.5, 'cycles' => 0, 'jobs' => 0])
        ->and($made['report']['service'])->toMatchArray(['level' => 'ok', 'progress' => 0])
        ->and($made['usage_logs'])->toBe([])
        ->and($made['position'])->toBe(240);

    // Not mapped to any package yet: a finished job does not age it.
    finishAnEssential('2026-10-13', '13:00', '2026-10-13T15:30:00+08:00');
    expect(tool($id)['report']['lifetime']['jobs'])->toBe(0);

    // Mapped at 0.75 h a job: the job already done counts at once, and the next one too.
    $this->putJson("/api/v1/equipment/profiles/{$this->essential}", ['lines' => [
        ['equipment_id' => $id, 'hours_per_job' => '0.75'],
        ['equipment_id' => $this->world->id('equipment:pressure-washer'), 'hours_per_job' => '0.75'],
    ]])->assertNoContent();
    expect(tool($id)['report']['lifetime'])->toEqual(['hours' => 2.25, 'cycles' => 1, 'jobs' => 1]);

    $second = finishAnEssential('2026-10-16', '09:00', '2026-10-16T11:45:00+08:00');
    $after = tool($id);
    expect($after['report']['lifetime'])->toEqual(['hours' => 3.0, 'cycles' => 2, 'jobs' => 2])
        // 1.5 h of the 2 h interval since it was registered: 75%, not yet due soon.
        ->and($after['report']['service'])->toMatchArray(['level' => 'ok', 'progress' => 0.75, 'hours_since' => 1.5, 'cycles_since' => 2])
        ->and($after['report']['utilization'])->toMatchArray(['hours' => 1.5, 'jobs' => 2, 'capacity_hours' => 300]);

    // A side job logged by hand: over the interval now, and the forecast says today.
    $logged = $this->postJson("/api/v1/equipment/{$id}/usage-logs", ['hours' => 0.75, 'cycles' => 3, 'note' => 'Showroom car'])->assertCreated()->json('data');
    expect($logged['report']['lifetime'])->toEqual(['hours' => 3.75, 'cycles' => 5, 'jobs' => 2])
        ->and($logged['report']['service'])->toMatchArray(['level' => 'overdue', 'level_label' => 'Overdue', 'progress' => 1.125])
        ->and($logged['report']['forecast'])->toMatchArray(['days' => 0, 'due_on' => '2026-10-16'])
        ->and($logged['usage_logs'][0])->toMatchArray(['hours' => '0.750', 'cycles' => 3, 'note' => 'Showroom car', 'logged_by_name' => 'Liza Samonte']);
    $this->postJson("/api/v1/equipment/{$id}/usage-logs", ['note' => 'nothing'])->assertStatus(422)
        ->assertJsonPath('error.details.fields.hours.0', 'Enter runtime hours or job cycles.');

    // Serviced: the interval restarts, the lifetime meter does not, and the reading at the time is kept.
    $this->travel(1)->hours();
    $serviced = $this->postJson("/api/v1/equipment/{$id}/maintenance-logs", ['kind' => 'repair', 'cost_cents' => 250_000, 'downtime_hours' => 1.25, 'performed_by' => 'Rico', 'parts' => 'Pump seal'])->assertCreated()->json('data');
    expect($serviced['report']['service'])->toMatchArray(['level' => 'ok', 'hours_since' => 0, 'cycles_since' => 0])
        ->and($serviced['report']['lifetime']['hours'])->toBe(3.75)
        ->and($serviced['report']['reliability'])->toMatchArray(['services' => 1, 'repairs' => 1, 'mtbf_hours' => 3.75, 'maintenance_cost_cents' => 250_000, 'total_cost_cents' => 1_450_000, 'availability_pct' => 75])
        ->and($serviced['maintenance_logs'][0])->toMatchArray(['kind' => 'repair', 'kind_label' => 'Repair / breakdown', 'meter_hours' => '3.750', 'meter_cycles' => 5, 'resets_service' => true])
        ->and($serviced['last_service_at'])->toBe('2026-10-16T04:45:00Z');

    // A clean inspection leaves the service clock alone.
    $this->travel(1)->hours();
    $this->postJson("/api/v1/equipment/{$id}/usage-logs", ['hours' => '0.5'])->assertCreated();
    $inspected = $this->postJson("/api/v1/equipment/{$id}/maintenance-logs", ['kind' => 'inspection', 'resets_service' => false])->assertCreated()->json('data');
    expect($inspected['report']['service']['hours_since'])->toBe(0.5)->and($inspected['last_service_at'])->toBe('2026-10-16T04:45:00Z');

    // Nothing was stored: re-opening the second job takes its hours back off the meter.
    $this->postJson("/api/v1/detailing/bookings/{$second}/status", ['status' => 'on_going'])->assertOk();
    expect(tool($id)['report']['lifetime'])->toEqual(['hours' => 3.5, 'cycles' => 4, 'jobs' => 1]);
    // And so does removing a log entry.
    $this->deleteJson("/api/v1/equipment/{$id}/usage-logs/{$logged['usage_logs'][0]['id']}")->assertNoContent();
    expect(tool($id)['report']['lifetime'])->toEqual(['hours' => 2.75, 'cycles' => 1, 'jobs' => 1]);

    // A tool with a history is retired, not deleted.
    $this->deleteJson("/api/v1/equipment/{$id}")->assertStatus(409);
    $retired = $this->postJson("/api/v1/equipment/{$id}/status", ['status' => 'retired'])->assertOk()->json('data');
    expect($retired['status_label'])->toBe('Retired')->and($retired['report']['health']['score'])->toBeLessThan(60);
    expect($this->getJson('/api/v1/equipment')->json('data.summary'))->toMatchArray(['total' => 24, 'active' => 23]);

    expect(collect($this->getJson('/api/v1/equipment/profiles')->assertOk()->json('data'))->where('package_name', 'The Essential')->pluck('equipment_name')->sort()->values()->all())
        ->toBe(['High-Pressure Washer', 'Spot Extractor']);
});

it('is staff-only, branch-scoped and module-gated; every staff role reads, managers write', function () {
    $compressor = $this->world->id('equipment:air-compressor');
    $write = fn (): TestResponse => $this->postJson("/api/v1/equipment/{$compressor}/usage-logs", ['hours' => '1']);

    equipAs('donmiguel@mekanikomor.ph');
    $this->getJson('/api/v1/equipment')->assertForbidden();
    $this->getJson("/api/v1/equipment/{$compressor}")->assertForbidden();

    equipAs('bay@mekanikomore.ph');
    $this->getJson("/api/v1/equipment/{$compressor}")->assertOk();
    $write()->assertForbidden();
    $this->postJson('/api/v1/equipment', ['name' => 'Bench Grinder'])->assertForbidden();

    // Pinned to the repair branch: the detailing branch's tools are missing, and the module is off where they work.
    equipAs('cashier@mekanikomore.ph');
    $this->getJson("/api/v1/equipment/{$compressor}")->assertNotFound();
    $this->getJson('/api/v1/equipment')->assertForbidden()->assertJsonPath('error.code', 'module_disabled');

    equipAs('manager.samahuzai@mekanikomore.ph');
    $write()->assertCreated();
    // An instant given with an offset is the same instant when read back, not the same clock time in UTC.
    expect($this->postJson("/api/v1/equipment/{$compressor}/usage-logs", ['hours' => '1', 'used_at' => '2026-10-12T08:00:00+08:00'])->assertCreated()->json('data.usage_logs.*.used_at'))->toContain('2026-10-12T00:00:00Z');
    $this->postJson('/api/v1/equipment', ['name' => 'air compressor (belt-drive)'])->assertStatus(422)
        ->assertJsonPath('error.details.fields.name.0', 'Another tool of this branch has that name.');
    $this->putJson("/api/v1/equipment/profiles/{$this->essential}", ['lines' => [['equipment_id' => $this->world->id('rival:equipment'), 'hours_per_job' => '1']]])->assertStatus(422);
    $this->getJson('/api/v1/equipment/'.$this->world->id('rival:equipment'))->assertNotFound();
});

it('takes a service entry\'s parts from stock, books them to Equipment Repairs & Maintenance, and puts them back when the entry is removed', function () {
    equipAs('manager.samahuzai@mekanikomore.ph');
    $compressor = $this->world->id('equipment:air-compressor');
    $branch = $this->world->id('samahuzai-binan');
    $cloths = $this->world->id('item:DTL-MF-CLOTH');
    $shampoo = $this->world->id('item:DTL-SHAMPOO-5L');
    $onHand = fn (string $item): string => collect($this->getJson("/api/v1/items/{$item}")->json('data.branches'))->firstWhere('branch_id', $branch)['on_hand'];
    $journal = fn (string $event) => collect($this->getJson("/api/v1/ledger/journal?event={$event}&per_page=100")->assertOk()->json('data'));

    $serviced = $this->postJson("/api/v1/equipment/{$compressor}/maintenance-logs", [
        'kind' => 'part_replacement',
        'cost_cents' => 30_000,
        'performed_by' => 'Rico Dimaculangan',
        'issued_parts' => [['item_id' => $cloths, 'quantity' => 2], ['item_id' => $shampoo, 'quantity' => '0.5']],
    ])->assertCreated()->json('data');
    $entry = $serviced['maintenance_logs'][0];

    // 2 cloths at ₱45 and half a jug at ₱850: ₱515 of parts on top of the ₱300 paid.
    expect($entry)->toMatchArray(['kind' => 'part_replacement', 'cost_cents' => 30_000, 'parts_cost_cents' => 51_500, 'total_cost_cents' => 81_500])
        ->and(collect($entry['issued_parts'])->sortBy('sku')->map(fn (array $part): array => [$part['item_id'], $part['sku'], $part['uom'], $part['quantity'], $part['value_cents']])->values()->all())->toBe([
            [$cloths, 'DTL-MF-CLOTH', 'pc', '2.000', 9_000],
            [$shampoo, 'DTL-SHAMPOO-5L', 'jug', '0.500', 42_500],
        ])
        // The tool's cost of ownership carries the parts with the rest.
        ->and($serviced['report']['reliability'])->toMatchArray(['services' => 2, 'maintenance_cost_cents' => 266_500, 'total_cost_cents' => 8_766_500])
        ->and($onHand($cloths))->toBe('18.000')
        ->and($onHand($shampoo))->toBe('3.500');

    $moves = collect($this->getJson('/api/v1/stock/moves?source_type=equipment_maintenance')->assertOk()->json('data'));
    expect($moves)->toHaveCount(2)
        ->and($moves->pluck('move_type')->unique()->all())->toBe(['consumption'])
        ->and($moves->pluck('source_reference')->unique()->all())->toBe(['Air Compressor (belt-drive)']);

    // The books: Dr Equipment Repairs & Maintenance / Cr Inventory, one entry a move.
    equipAs('owner@mekanikomore.ph');
    $posted = $journal('stock_maintenance');
    expect($posted)->toHaveCount(2)
        ->and($posted->pluck('reference')->unique()->all())->toBe(['Air Compressor (belt-drive)'])
        ->and($posted->flatMap(fn (array $e): array => $e['lines'])->where('account_code', '6000')->sum('debit_cents'))->toBe(51_500)
        ->and($posted->flatMap(fn (array $e): array => $e['lines'])->where('account_code', '1200')->sum('credit_cents'))->toBe(51_500);

    // What cannot be issued: more than the shelf holds (where the branch forbids going negative), the same item twice,
    // something the stock room does not keep.
    $this->patchJson("/api/v1/branches/{$branch}", ['negative_stock_policy' => 'block'])->assertOk();
    equipAs('manager.samahuzai@mekanikomore.ph');
    $post = fn (array $parts): TestResponse => $this->postJson("/api/v1/equipment/{$compressor}/maintenance-logs", ['kind' => 'repair', 'issued_parts' => $parts]);
    $post([['item_id' => $cloths, 'quantity' => 500]])->assertStatus(409)->assertJsonPath('error.details.reason', 'insufficient_stock');
    $post([['item_id' => $cloths, 'quantity' => 1], ['item_id' => $cloths, 'quantity' => 1]])->assertStatus(422)->assertJsonPath('error.details.fields', ['issued_parts.1.item_id' => ['That item is listed twice.']]);
    $post([['item_id' => $this->world->id('rival:equipment'), 'quantity' => 1]])->assertStatus(422)->assertJsonPath('error.details.fields', ['issued_parts.0.item_id' => ['Pick an item the stock room keeps.']]);
    $post([['item_id' => $cloths, 'quantity' => 0]])->assertStatus(422);
    // A refused entry leaves nothing behind.
    expect(tool($compressor)['maintenance_logs'])->toHaveCount(2)->and($onHand($cloths))->toBe('18.000');

    // Removed: the parts are back at what they left at, and the expense is taken back.
    $this->deleteJson("/api/v1/equipment/{$compressor}/maintenance-logs/{$entry['id']}")->assertNoContent();
    expect($onHand($cloths))->toBe('20.000')
        ->and($onHand($shampoo))->toBe('4.000')
        ->and(tool($compressor)['report']['reliability'])->toMatchArray(['services' => 1, 'maintenance_cost_cents' => 185_000]);

    equipAs('owner@mekanikomore.ph');
    $returned = $journal('stock_maintenance_return');
    expect($returned)->toHaveCount(2)
        ->and($returned->flatMap(fn (array $e): array => $e['lines'])->where('account_code', '6000')->sum('credit_cents'))->toBe(51_500)
        ->and($returned->flatMap(fn (array $e): array => $e['lines'])->where('account_code', '1200')->sum('debit_cents'))->toBe(51_500);
});

it('raises equipment alerts on the derived alerts list, for staff who watch that floor', function () {
    $espresso = $this->world->id('equipment:espresso-machine');
    $service = "equipment:{$espresso}:service";
    $equipmentAlerts = fn () => collect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->filter(fn (array $a): bool => str_starts_with($a['id'], 'equipment:'))->values();

    equipAs('manager.samahuzai@mekanikomore.ph');
    expect($equipmentAlerts()->all())->toBe([[
        'id' => $service,
        'kind' => 'equipment_service_due_soon',
        'severity' => 'warning',
        'title' => 'Descale and backflush — Espresso Machine',
        'body' => 'Due soon · 83% through its service interval (cups served) · about 7 days left at the recent pace.',
        'vehicle_id' => null,
        'href' => "/shop/equipment/{$espresso}",
        'days_remaining' => 7,
        'read' => false,
        'dismissed' => false,
    ]]);

    // Past the limit, and a tool close to its rated life.
    $this->postJson("/api/v1/equipment/{$espresso}/usage-logs", ['cycles' => 900])->assertCreated();
    $gun = $this->postJson('/api/v1/equipment', ['name' => 'Old Heat Gun', 'category' => 'coating', 'expected_life_hours' => '1200', 'baseline_hours' => '1140'], ['Idempotency-Key' => 'gun'])->assertCreated()->json('data.id');
    $alerts = $equipmentAlerts()->keyBy('id');
    expect($alerts->keys()->all())->toBe([$service, "equipment:{$gun}:life"])
        ->and($alerts[$service])->toMatchArray(['kind' => 'equipment_service_overdue', 'severity' => 'critical', 'body' => 'Overdue · 101% through its service interval (cups served).', 'days_remaining' => 0])
        ->and($alerts["equipment:{$gun}:life"])->toMatchArray(['kind' => 'equipment_replace_soon', 'severity' => 'warning', 'title' => 'Old Heat Gun is close to its rated life', 'body' => '1,140 of 1,200 rated hours used (95%).']);

    // Read and dismiss state works on them as on any alert, and is the caller's own.
    $this->postJson('/api/v1/alerts/dismiss', ['alert_ids' => [$service]])->assertNoContent();
    expect($equipmentAlerts()->pluck('id')->all())->toBe(["equipment:{$gun}:life"]);
    equipAs('bay@mekanikomore.ph');
    expect($equipmentAlerts()->pluck('id')->all())->toBe([$service, "equipment:{$gun}:life"]);

    // Looking at the repair branch, or pinned to it, or on the customer side: none.
    expect(collect($this->getJson('/api/v1/alerts', ['X-Branch-Id' => $this->world->id('mekanikomor-binan')])->assertOk()->json('data'))->filter(fn (array $a): bool => str_starts_with($a['id'], 'equipment:')))->toBeEmpty();
    equipAs('cashier@mekanikomore.ph');
    expect($equipmentAlerts())->toBeEmpty();
    equipAs('donmiguel@mekanikomor.ph');
    expect($equipmentAlerts())->toBeEmpty();

    // Serviced, retired: the alert goes with the condition behind it.
    equipAs('manager.samahuzai@mekanikomore.ph');
    $this->postJson('/api/v1/alerts/restore', ['alert_ids' => [$service]])->assertNoContent();
    $this->postJson("/api/v1/equipment/{$espresso}/maintenance-logs", ['kind' => 'preventive'])->assertCreated();
    $this->postJson("/api/v1/equipment/{$gun}/status", ['status' => 'retired'])->assertOk();
    expect($equipmentAlerts())->toBeEmpty();
});
