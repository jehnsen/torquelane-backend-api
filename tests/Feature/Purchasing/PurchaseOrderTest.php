<?php

declare(strict_types=1);

use App\Domain\PurchaseOrders\PurchaseOrderExport;
use App\Models\AuditLog;
use App\Models\FleetPart;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Purchase orders for a customer account's own spare parts: raised from the
 * server's own forecast, numbered in the transaction, issued within the
 * issuer's band, received into the account's own stock, never edited once
 * issued (R7).
 *
 * Seed facts (frozen 2026-10-08 10:00 Manila): Actimed's 6-week forecast
 * short 4 oil filters (₱380, OEM Direct Supply PH) and 1 camber bolt (₱340,
 * Bridgestone Tire Center); PO-2026-0001 is sent (2 timing belts + 2
 * tensioners), PO-2026-0002 a ₱9,800 draft; the series continues at 0003.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

function poSignIn(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

function part(string $source): string
{
    return test()->world->id('part:fc-actimed:'.$source);
}

it('raises one draft per vendor from the server\'s own forecast, numbered in order', function () {
    poSignIn('purchasing@mekanikomor.ph');

    $response = $this->postJson('/api/v1/purchase-orders', [
        'part_ids' => [part('p-oil-filter'), part('p-camber-bolt')],
        'horizon_weeks' => 6,
        'notes' => 'Q4 top-up',
        // Never read: quantities and prices come from the forecast.
        'quantity' => 999,
        'total_cents' => 1,
    ])->assertCreated();

    $orders = collect($response->json('data'))->keyBy('vendor');
    expect($orders->keys()->sort()->values()->all())->toBe(['Bridgestone Tire Center', 'OEM Direct Supply PH'])
        ->and($orders->pluck('reference')->sort()->values()->all())->toBe(['PO-2026-0003', 'PO-2026-0004']);

    $oem = $orders['OEM Direct Supply PH'];
    expect($oem['status'])->toBe('draft')
        ->and($oem['notes'])->toBe('Q4 top-up')
        ->and($oem['lines'])->toHaveCount(1)
        ->and($oem['lines'][0]['quantity'])->toBe(4)
        ->and($oem['lines'][0]['unit_cost_cents'])->toBe(38000)
        ->and($oem['lines'][0]['line_total_cents'])->toBe(152000)
        ->and($oem['total_cents'])->toBe(152000)
        ->and($oem['lines'][0]['service_task_ids'])->toBe([$this->world->id('task:oil-filter')])
        ->and($oem['lines'][0]['vehicle_ids'])->toHaveCount(7)
        ->and($oem['events'][0]['status'])->toBe('draft')
        ->and($oem['created_by_name'])->toBe('Grace Villanueva');

    expect(asSystem(fn () => AuditLog::query()->where('entity_type', 'purchase_order')->where('action', 'created')->count()))->toBe(2);

    // The open orders now cover those due items: no shortfall left to order.
    $rows = collect($this->getJson('/api/v1/demand-forecast')->assertOk()->json('data.rows'))->keyBy('part.id');
    expect($rows->get(part('p-oil-filter')))->toBeNull()
        ->and($rows->get(part('p-camber-bolt')))->toBeNull();
});

it('refuses parts with no shortfall left to order', function () {
    poSignIn('purchasing@mekanikomor.ph');
    $before = asSystem(fn () => PurchaseOrder::query()->count());

    $this->postJson('/api/v1/purchase-orders', ['part_ids' => [part('p-engine-oil')]])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.part_ids.0', 'None of these parts has a shortfall left to order over this horizon.');

    expect(asSystem(fn () => PurchaseOrder::query()->count()))->toBe($before);
});

it('makes staff name the account, and keeps every account to its own parts', function () {
    poSignIn('owner@mekanikomore.ph');
    $this->postJson('/api/v1/purchase-orders', ['part_ids' => [part('p-oil-filter')]])->assertUnprocessable();
    $this->postJson('/api/v1/purchase-orders', ['customer_account_id' => $this->world->id('fc-actimed'), 'part_ids' => [part('p-oil-filter')]])->assertCreated();

    // Northwind's own forecast never reaches Actimed's shelf.
    poSignIn('fleet@northwind.ph');
    $this->postJson('/api/v1/purchase-orders', ['part_ids' => [part('p-camber-bolt')]])->assertUnprocessable();
    $this->postJson('/api/v1/purchase-orders', ['customer_account_id' => $this->world->id('fc-actimed'), 'part_ids' => [part('p-camber-bolt')]])->assertNotFound();
    $this->getJson('/api/v1/purchase-orders/'.$this->world->id('po-seed-0002'))->assertNotFound();
});

it('needs po:issue to raise or move an order', function () {
    poSignIn('viewer@mekanikomore.ph');

    $this->postJson('/api/v1/purchase-orders', ['part_ids' => [part('p-oil-filter')]])->assertForbidden();
    $this->postJson('/api/v1/purchase-orders/'.$this->world->id('po-seed-0002').'/send')->assertForbidden();
    $this->getJson('/api/v1/purchase-orders/'.$this->world->id('po-seed-0002'))->assertOk();
});

it('takes no new orders for a suspended account', function () {
    poSignIn('owner@mekanikomore.ph');

    $this->postJson('/api/v1/purchase-orders', ['customer_account_id' => $this->world->id('fc-bayani'), 'part_ids' => [part('p-oil-filter')]])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_suspended');
});

it('issues an order only within the issuer\'s band', function () {
    $draft = '/api/v1/purchase-orders/'.$this->world->id('po-seed-0002');

    // Actimed's operations ceiling lowered to ₱5,000: the ₱9,800 draft is above it.
    poSignIn('owner@mekanikomore.ph');
    $this->patchJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed'), ['approval_threshold_overrides' => ['ops_approval_under_cents' => 500000]])->assertOk();

    poSignIn('purchasing@mekanikomor.ph');
    $this->getJson($draft)->assertJsonPath('data.can_send', false);
    $this->postJson($draft.'/send')
        ->assertForbidden()
        ->assertJsonPath('error.message', 'Issuing ₱9800.00 is above your approval limit; it needs a Fleet Manager.');

    poSignIn('donmiguel@mekanikomor.ph');
    $this->getJson($draft)->assertJsonPath('data.can_send', true);
    $this->postJson($draft.'/send')
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.sent_by_name', 'Don Miguel')
        ->assertJsonPath('data.next_statuses', ['received', 'cancelled']);

    $this->postJson($draft.'/send')->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
});

it('receives an order into the account\'s own stock', function () {
    poSignIn('purchasing@mekanikomor.ph');
    $stock = fn (string $source): int => asSystem(fn (): int => FleetPart::query()->findOrFail(part($source))->current_stock);
    [$belts, $tensioners] = [$stock('p-timing-belt'), $stock('p-timing-tensioner')];

    $this->postJson('/api/v1/purchase-orders/'.$this->world->id('po-seed-0001').'/receive')
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.received_by_name', 'Grace Villanueva');

    expect($stock('p-timing-belt'))->toBe($belts + 2)
        ->and($stock('p-timing-tensioner'))->toBe($tensioners + 2)
        ->and(asSystem(fn () => AuditLog::query()->where('entity_type', 'fleet_part')->where('action', 'restocked')->count()))->toBe(2);

    $this->postJson('/api/v1/purchase-orders/'.$this->world->id('po-seed-0001').'/receive')->assertStatus(409);
});

it('cancels with a reason, after which nothing moves', function () {
    poSignIn('purchasing@mekanikomor.ph');
    $draft = '/api/v1/purchase-orders/'.$this->world->id('po-seed-0002');

    $this->postJson($draft.'/cancel')->assertUnprocessable();
    $this->postJson($draft.'/cancel', ['reason' => 'Supplier out of stock'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', 'Supplier out of stock')
        ->assertJsonPath('data.events.1.note', 'Supplier out of stock');
    $this->postJson($draft.'/send')->assertStatus(409);
});

it('never edits an issued order or deletes any order, in the database itself', function () {
    $sent = $this->world->id('po-seed-0001');

    asSystem(function () use ($sent): void {
        expect(fn () => DB::transaction(fn () => PurchaseOrderLine::query()->where('purchase_order_id', $sent)->update(['quantity' => 99])))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23001'));
        expect(fn () => DB::transaction(fn () => PurchaseOrder::query()->whereKey($sent)->update(['vendor' => 'Someone else'])))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23001'));
        expect(fn () => DB::transaction(fn () => PurchaseOrder::query()->whereKey($this->world->id('po-seed-0002'))->delete()))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23001'));
    });
});

it('exports the list and a single order as CSV or XLSX', function () {
    poSignIn('donmiguel@mekanikomor.ph');

    $csv = $this->get('/api/v1/purchase-orders/export?format=csv')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="purchase-orders.csv"')
        ->getContent();
    $lines = explode("\n", trim((string) $csv));
    expect(array_shift($lines))->toBe("\u{FEFF}Reference,Vendor,Status,Created,\"Created by\",Description,Quantity,\"Unit cost\",\"Line total\"");

    // The same orders, in the same order, as the JSON list: one row per line, money in pesos.
    $expected = [];
    foreach ($this->getJson('/api/v1/purchase-orders')->json('data') as $order) {
        foreach ($order['lines'] as $line) {
            $expected[] = [$order['reference'], $order['vendor'], $order['status'], $order['created_on'], $order['created_by_name'], $line['description'], (string) $line['quantity'], PurchaseOrderExport::pesos($line['unit_cost_cents']), PurchaseOrderExport::pesos($line['line_total_cents'])];
        }
    }
    expect(array_map(fn (string $line): array => str_getcsv($line, escape: ''), $lines))->toBe($expected)
        ->and($expected)->toHaveCount(3);

    $xlsx = $this->get('/api/v1/purchase-orders/'.$this->world->id('po-seed-0001').'/export')->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="PO-2026-0001.xlsx"')
        ->getContent();
    $path = sys_get_temp_dir().'/po-'.bin2hex(random_bytes(6)).'.xlsx';
    file_put_contents($path, (string) $xlsx);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $zip->close();
    unlink($path);

    expect($workbook)->toContain('<sheet name="PO-2026-0001"')
        ->and($sheet)->toContain('<t>Line total</t>')
        ->and(substr_count($sheet, '<row '))->toBe(3)
        ->and($sheet)->toContain('<c r="E2" s="2"><v>');
});
