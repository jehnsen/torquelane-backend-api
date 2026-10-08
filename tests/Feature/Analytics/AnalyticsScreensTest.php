<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The purpose-built reads behind the heavy screens: the fleet dashboard,
 * schedule and reports, the approvals queue, and the shop's home, reports
 * and client book. Their series are golden-tested (PartsAnalyticsDbGoldenTest,
 * ShopDbGoldenTest); this covers what is specific to the endpoints.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

function screensSignIn(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

it('reports only orders closed inside the chosen window', function () {
    screensSignIn('donmiguel@mekanikomor.ph');

    $year = $this->getJson('/api/v1/analytics/reports?months=12')->assertOk()->json('data');
    $quarter = $this->getJson('/api/v1/analytics/reports?months=3')->assertOk()->json('data');

    expect($quarter['monthly_costs'])->toHaveCount(3)
        ->and($quarter['closed_orders'])->toBeLessThan($year['closed_orders'])
        ->and($quarter['total_spend_cents'])->toBe(array_sum(array_column($quarter['monthly_costs'], 'total_cents')))
        ->and($quarter['preventive_share_pct'])->toBe((int) round($quarter['preventive_spend_cents'] / $quarter['total_spend_cents'] * 100))
        ->and($quarter['period_km'])->toBeLessThan($year['period_km']);

    $this->getJson('/api/v1/analytics/reports?months=5')->assertUnprocessable();
});

it('groups the schedule by lead time and filters it by band', function () {
    screensSignIn('donmiguel@mekanikomor.ph');

    $all = $this->getJson('/api/v1/analytics/schedule')->assertOk()->json('data');
    $overdue = $this->getJson('/api/v1/analytics/schedule?status=overdue')->assertOk()->json('data');

    expect(array_column($all['groups'], 'key'))->toBe(['overdue', 'next_7_days', 'next_30_days', 'next_90_days'])
        ->and($overdue['groups'][0]['count'])->toBe($all['demand']['overdue']['count'])
        ->and($overdue['groups'][0]['estimated_cost_cents'])->toBe($all['demand']['overdue']['estimated_cost_cents'])
        ->and(array_sum(array_column(array_slice($overdue['groups'], 1), 'count')))->toBe(0)
        ->and($all['listed'] + $all['beyond_horizon'])->toBeGreaterThan($all['listed'])
        // The bands are the dashboard's own figures.
        ->and($this->getJson('/api/v1/analytics/dashboard')->json('data.demand'))->toBe($all['demand']);
});

it('lets staff narrow the fleet screens to one account, and nobody else\'s', function () {
    screensSignIn('owner@mekanikomore.ph');
    $this->getJson('/api/v1/analytics/dashboard?customer_account_id='.$this->world->id('fc-actimed'))
        ->assertOk()
        ->assertJsonPath('data.summary.total', 16);
    $this->getJson('/api/v1/analytics/dashboard?customer_account_id='.$this->world->id('rival:account'))->assertNotFound();

    screensSignIn('fleet@northwind.ph');
    $this->getJson('/api/v1/analytics/reports?customer_account_id='.$this->world->id('fc-actimed'))->assertNotFound();
});

it('queues approvals by who may decide them', function () {
    // A purchasing officer decides only within the operations ceiling.
    screensSignIn('purchasing@mekanikomor.ph');
    $officer = $this->getJson('/api/v1/requests')->assertOk()->json('data');
    screensSignIn('donmiguel@mekanikomor.ph');
    $manager = $this->getJson('/api/v1/requests')->assertOk()->json('data');

    expect($manager['pending'])->not->toBeEmpty()
        ->and(array_unique(array_column($manager['pending'], 'can_approve')))->toBe([true])
        ->and($manager['my_pending']['value_cents'])->toBeGreaterThanOrEqual($officer['my_pending']['value_cents'])
        ->and(array_column($manager['pending'], 'work_order_id'))->toBe(array_column($officer['pending'], 'work_order_id'));
    foreach ($officer['pending'] as $request) {
        expect($request['can_approve'])->toBe($request['pending_value_cents'] <= 5000000);
    }

    $entered = array_column($manager['pending'], 'pending_approval_entered_at');
    $sorted = $entered;
    sort($sorted);
    expect($entered)->toBe($sorted);
});

it('serves the shop home in one call, to staff only', function () {
    screensSignIn('advisor@mekanikomore.ph');
    $home = $this->getJson('/api/v1/shop/home')->assertOk()->json('data');

    expect(array_keys($home))->toBe(['date', 'arriving', 'in_progress', 'awaiting_approval', 'ready_for_collection', 'floor', 'revenue'])
        ->and($home['revenue']['week_start'])->toBe('2026-10-05')
        ->and($home['awaiting_approval']['count'])->toBe($this->getJson('/api/v1/shop/approvals')->json('data.count'))
        ->and($home['ready_for_collection']['count'])->toBe(count($this->getJson('/api/v1/shop/ready-for-collection')->json('data')));
    foreach ($home['ready_for_collection']['orders'] as $order) {
        expect($order['vehicle']['plate_number'])->not->toBeEmpty()->and($order['customer_name'])->not->toBeEmpty();
    }

    screensSignIn('donmiguel@mekanikomor.ph');
    $this->getJson('/api/v1/shop/home')->assertForbidden();
    $this->getJson('/api/v1/shop/clients')->assertForbidden();
});

it('rolls up the client book, biggest spender this month first', function () {
    screensSignIn('advisor@mekanikomore.ph');
    $clients = $this->getJson('/api/v1/shop/clients')->assertOk()->json('data');

    $spend = array_column($clients, 'spend_this_period_cents');
    $sorted = $spend;
    rsort($sorted);
    expect($spend)->toBe($sorted)
        ->and(array_column($clients, 'customer_account_id'))->toContain($this->world->id('fc-bayani'));

    $actimed = $this->getJson('/api/v1/shop/clients/'.$this->world->id('fc-actimed'))->assertOk()->json('data');
    expect($actimed['rollup'])->toBe(collect($clients)->firstWhere('customer_account_id', $this->world->id('fc-actimed')))
        ->and($actimed['vehicles'])->toHaveCount(16)
        ->and($actimed['effective_settings']['ops_approval_under_cents'])->toBe(5000000)
        ->and($actimed['approval_overrides'])->toBe([]);

    $this->getJson('/api/v1/shop/clients/'.$this->world->id('rival:account'))->assertNotFound();
});

it('reports the shop\'s book over the chosen months', function () {
    screensSignIn('owner@mekanikomore.ph');
    $report = $this->getJson('/api/v1/shop/reports?months=3')->assertOk()->json('data');

    expect($report['maintenance_mix'])->toHaveCount(3)
        ->and($report['utilisation'])->toHaveCount(21)
        ->and($report['parts_margin']['markup_pct'])->toBe(22)
        ->and(count($report['revenue_by_service_item']))->toBeLessThanOrEqual(10);
});

it('auto-schedules the overdue queue once, as the preview showed it', function () {
    // The seed covers every overdue item with a live order: cancel Actimed's
    // live orders so their items fall back into the queue.
    screensSignIn('advisor@mekanikomore.ph');
    $this->getJson('/api/v1/analytics/auto-schedule?customer_account_id='.$this->world->id('fc-actimed'))->assertJsonPath('data.count', 0);
    foreach ($this->getJson('/api/v1/work-orders?stage=active&per_page=100&customer_account_id='.$this->world->id('fc-actimed'))->json('data') as $order) {
        $this->postJson("/api/v1/work-orders/{$order['id']}/cancel", ['reason' => 'Rebooking']);
    }

    screensSignIn('donmiguel@mekanikomor.ph');
    $preview = $this->getJson('/api/v1/analytics/auto-schedule')->assertOk()->json('data');

    expect($preview['count'])->toBeGreaterThan(0)
        ->and($preview['can_commit'])->toBeTrue()
        ->and($preview['estimate_cents'])->toBe(array_sum(array_column($preview['proposals'], 'estimate_cents')))
        ->and(array_unique(array_column(array_column($preview['proposals'], 'item'), 'status')))->toBe(['overdue'])
        ->and($preview['proposals'][0]['scheduled_for'])->toBe('2026-10-09');

    $created = $this->postJson('/api/v1/work-orders/auto-schedule')->assertCreated()->json('data');
    expect($created['created'])->toBe($preview['count'])
        ->and(array_unique(array_column($created['work_orders'], 'status')))->toBe(['draft'])
        ->and(array_map(fn (array $o): array => [$o['vehicle_id'], $o['task_ids'][0], $o['scheduled_for'], $o['priority']], $created['work_orders']))
        ->toEqualCanonicalizing(array_map(fn (array $p): array => [$p['vehicle']['id'], $p['item']['service_task_id'], $p['scheduled_for'], $p['priority']], $preview['proposals']))
        // The preview's estimate is what the drafts are priced at: parts plus labour at the shop rate.
        ->and(array_sum(array_map(fn (array $o): int => array_sum(array_column($o['lines'], 'line_cost_cents')), $created['work_orders'])))->toBe($preview['estimate_cents']);

    // Now covered by live drafts: nothing left to book.
    $this->getJson('/api/v1/analytics/auto-schedule')->assertJsonPath('data.count', 0);
    $this->postJson('/api/v1/work-orders/auto-schedule')->assertOk()->assertJsonPath('data.created', 0);
});

it('never books work for an account that takes none, nor lets a viewer book any', function () {
    screensSignIn('owner@mekanikomore.ph');
    $this->getJson('/api/v1/analytics/auto-schedule?customer_account_id='.$this->world->id('fc-bayani'))->assertOk()->assertJsonPath('data.count', 0);

    screensSignIn('viewer@mekanikomore.ph');
    $this->getJson('/api/v1/analytics/auto-schedule')->assertOk()->assertJsonPath('data.can_commit', false);
    $this->postJson('/api/v1/work-orders/auto-schedule')->assertForbidden();
});
