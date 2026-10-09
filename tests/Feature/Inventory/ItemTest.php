<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The shop's own items. Seed facts (frozen 2026-10-08 10:00 Manila): eleven
 * items; at the repair store the oil filter has 13 on hand (3 opened at ₱360,
 * 10 received at ₱350 → ₱352.31 average), reorder point 8, bin A-01,
 * priced ₱520; the detailing branch keeps shampoo and microfibre cloths.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

function invSignIn(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

it('lists the items by SKU, each with its branches\' settings and stock', function () {
    invSignIn('owner@mekanikomore.ph');

    $response = $this->getJson('/api/v1/items')->assertOk();
    $skus = array_column($response->json('data'), 'sku');

    expect($response->json('meta.total'))->toBe(11)
        ->and($skus)->toBe(['04465-0K340', '08889-80015', '17801-0L040', '90915-YZZD4', 'CAM-BLT-A', 'DTL-MF-CLOTH', 'DTL-SHAMPOO-5L', 'HX7-5W30-1L', 'RAG-SHOP', 'SVC-DIAG', 'WPR-BLD-22']);

    $oil = collect($response->json('data'))->firstWhere('sku', '90915-YZZD4');
    $repair = collect($oil['branches'])->firstWhere('branch_id', $this->world->id('mekanikomor-binan'));

    expect($oil['name'])->toBe('Engine oil filter')
        ->and($oil['uom'])->toBe('pc')
        ->and($oil['purchase_uom'])->toBe('box')
        ->and($oil['purchase_uom_factor'])->toBe('10.000')
        ->and($oil['preferred_vendor_name'])->toBe('Toyota Shaw Service Center')
        ->and($repair['on_hand'])->toBe('13.000')
        ->and($repair['avg_cost_cents'])->toBe(35231)
        // 13 × ₱352.31 = ₱4,580.03 (rounded once)
        ->and($repair['value_cents'])->toBe(458003)
        ->and($repair['reorder_point'])->toBe('8.000')
        ->and($repair['reorder_qty'])->toBe('20.000')
        ->and($repair['bin'])->toBe('A-01')
        ->and($repair['effective_price_cents'])->toBe(52000)
        ->and($oil['totals']['on_hand'])->toBe('13.000');
});

it('searches by SKU, name or barcode and filters by type', function () {
    invSignIn('owner@mekanikomore.ph');

    expect(array_column($this->getJson('/api/v1/items?q=coolant')->json('data'), 'sku'))->toBe(['08889-80015'])
        ->and(array_column($this->getJson('/api/v1/items?q=DTL-')->json('data'), 'sku'))->toBe(['DTL-MF-CLOTH', 'DTL-SHAMPOO-5L'])
        ->and(array_column($this->getJson('/api/v1/items?item_type=service_fee')->json('data'), 'sku'))->toBe(['SVC-DIAG'])
        ->and($this->getJson('/api/v1/items?is_stocked=0')->json('meta.total'))->toBe(1);

    $this->getJson('/api/v1/items?item_type=gadget')->assertUnprocessable();
});

it('adds an item, with a unique SKU and barcode whatever their case', function () {
    invSignIn('owner@mekanikomore.ph');
    $body = ['sku' => 'BAT-NS60', 'barcode' => '4800123456789', 'name' => 'Battery NS60', 'item_type' => 'retail', 'uom' => 'pc', 'default_price_cents' => 650000, 'tax_class' => 'vatable'];

    $this->postJson('/api/v1/items', $body)
        ->assertCreated()
        ->assertJsonPath('data.sku', 'BAT-NS60')
        ->assertJsonPath('data.is_stocked', true)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.purchase_uom_factor', '1.000')
        ->assertJsonPath('data.totals.on_hand', '0.000');

    $this->postJson('/api/v1/items', [...$body, 'sku' => 'bat-ns60', 'barcode' => '4800000000000'])
        ->assertUnprocessable()->assertJsonPath('error.details.fields.sku.0', 'Another item already uses that SKU.');
    $this->postJson('/api/v1/items', [...$body, 'sku' => 'BAT-NS70', 'barcode' => '4800123456789'])
        ->assertUnprocessable()->assertJsonPath('error.details.fields.barcode.0', 'Another item already uses that barcode.');
    $this->postJson('/api/v1/items', ['name' => 'No sku'])->assertUnprocessable();
});

it('never stocks a service fee, and a purchase factor needs its unit', function () {
    invSignIn('owner@mekanikomore.ph');

    $this->postJson('/api/v1/items', ['sku' => 'SVC-TOW', 'name' => 'Towing', 'item_type' => 'service_fee', 'uom' => 'service', 'is_stocked' => true])
        ->assertCreated()->assertJsonPath('data.is_stocked', false);

    $this->postJson('/api/v1/items', ['sku' => 'X-1', 'name' => 'Case goods', 'item_type' => 'part', 'uom' => 'pc', 'purchase_uom_factor' => 12])
        ->assertUnprocessable()->assertJsonPath('error.details.fields.purchase_uom_factor.0', 'Name the purchase unit this factor is for.');
    $this->postJson('/api/v1/items', ['sku' => 'X-1', 'name' => 'Case goods', 'item_type' => 'part', 'uom' => 'pc', 'purchase_uom' => 'case', 'purchase_uom_factor' => 12.0005])
        ->assertUnprocessable();
    $this->postJson('/api/v1/items', ['sku' => 'X-1', 'name' => 'Case goods', 'item_type' => 'part', 'uom' => 'pc', 'purchase_uom' => 'case', 'purchase_uom_factor' => 12])
        ->assertCreated()->assertJsonPath('data.purchase_uom_factor', '12.000');
});

it('changes an item but not its stock unit once stock has moved, and never deletes one', function () {
    invSignIn('owner@mekanikomore.ph');
    $oil = $this->world->id('item:90915-YZZD4');

    $this->patchJson("/api/v1/items/{$oil}", ['name' => 'OEM engine oil filter', 'default_price_cents' => 55000])
        ->assertOk()->assertJsonPath('data.name', 'OEM engine oil filter')->assertJsonPath('data.default_price_cents', 55000);
    $this->patchJson("/api/v1/items/{$oil}", ['uom' => 'box'])->assertUnprocessable();
    $this->patchJson("/api/v1/items/{$oil}", ['is_stocked' => false])->assertUnprocessable();
    $this->patchJson("/api/v1/items/{$oil}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    // A deactivated item drops off the list unless asked for.
    expect(array_column($this->getJson('/api/v1/items?q=oil+filter')->json('data'), 'sku'))->toBe([])
        ->and(array_column($this->getJson('/api/v1/items?q=oil+filter&include_inactive=1')->json('data'), 'sku'))->toBe(['90915-YZZD4']);

    $this->deleteJson("/api/v1/items/{$oil}")->assertStatus(405);

    // An unused item may still change its unit.
    $unused = $this->world->id('item:SVC-DIAG');
    $this->patchJson("/api/v1/items/{$unused}", ['uom' => 'visit'])->assertOk()->assertJsonPath('data.uom', 'visit');
});

it('keeps the shop\'s stock room to staff, reading with inventory:view and changing with inventory:manage', function () {
    $body = ['sku' => 'NEW-1', 'name' => 'New thing', 'item_type' => 'part', 'uom' => 'pc'];

    // The customer side never sees it.
    invSignIn('donmiguel@mekanikomor.ph');
    $this->getJson('/api/v1/items')->assertForbidden();
    $this->getJson('/api/v1/items/'.$this->world->id('item:90915-YZZD4'))->assertForbidden();
    $this->getJson('/api/v1/stock/on-hand')->assertForbidden();
    $this->postJson('/api/v1/items', $body)->assertForbidden();

    // Front-of-house staff read.
    foreach (['advisor@mekanikomore.ph', 'bay@mekanikomore.ph', 'cashier@mekanikomore.ph'] as $email) {
        invSignIn($email);
        $this->getJson('/api/v1/items')->assertOk();
        $this->postJson('/api/v1/items', $body)->assertForbidden();
    }

    // A branch manager and the owner manage.
    invSignIn('manager.samahuzai@mekanikomore.ph');
    $this->postJson('/api/v1/items', $body)->assertCreated();
    invSignIn('owner@mekanikomore.ph');
    $this->postJson('/api/v1/items', [...$body, 'sku' => 'NEW-2'])->assertCreated();
});

it('sets an item\'s settings only in a branch the caller works in', function () {
    $oil = $this->world->id('item:90915-YZZD4');
    $repair = $this->world->id('mekanikomor-binan');
    $detailing = $this->world->id('samahuzai-binan');

    invSignIn('manager.samahuzai@mekanikomore.ph');
    $this->putJson("/api/v1/items/{$oil}/branch-settings/{$repair}", ['reorder_point' => 1])->assertNotFound();
    $this->putJson("/api/v1/items/{$oil}/branch-settings/{$detailing}", ['reorder_point' => 2.5, 'reorder_qty' => 10, 'bin' => 'Z-9', 'price_override_cents' => 49900])
        ->assertOk();
    $setting = collect($this->getJson("/api/v1/items/{$oil}")->json('data.branches'))->firstWhere('branch_id', $detailing);
    expect($setting['reorder_point'])->toBe('2.500')
        ->and($setting['bin'])->toBe('Z-9')
        ->and($setting['price_override_cents'])->toBe(49900)
        ->and($setting['effective_price_cents'])->toBe(49900);

    // Clearing a setting falls back to the item's price.
    $this->putJson("/api/v1/items/{$oil}/branch-settings/{$detailing}", ['price_override_cents' => null, 'bin' => ''])->assertOk();
    $setting = collect($this->getJson("/api/v1/items/{$oil}")->json('data.branches'))->firstWhere('branch_id', $detailing);
    expect($setting['price_override_cents'])->toBeNull()->and($setting['bin'])->toBeNull()->and($setting['effective_price_cents'])->toBe(52000);

    // A pinned manager sees only their own branch's figures.
    expect(array_column($this->getJson("/api/v1/items/{$oil}")->json('data.branches'), 'branch_id'))->toBe([$detailing]);

    invSignIn('advisor@mekanikomore.ph');
    $this->putJson("/api/v1/items/{$oil}/branch-settings/{$repair}", ['reorder_point' => 1])->assertForbidden();
    $this->putJson("/api/v1/items/{$oil}/branch-settings/{$repair}", ['reorder_point' => -1])->assertUnprocessable();
});

it('audits what changes', function () {
    invSignIn('owner@mekanikomore.ph');
    $id = $this->postJson('/api/v1/items', ['sku' => 'AUD-1', 'name' => 'Audited', 'item_type' => 'part', 'uom' => 'pc'])->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/items/{$id}", ['name' => 'Audited twice'])->assertOk();

    $actions = asSystem(fn () => AuditLog::query()->where('entity_type', 'item')->where('entity_id', $id)->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());
    expect($actions)->toBe(['created', 'updated']);
});
