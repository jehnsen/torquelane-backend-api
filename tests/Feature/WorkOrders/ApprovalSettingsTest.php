<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Organization defaults (seeded from ../web's approvalSettings) → branch
 * override → customer account override (Phase 1's sparse jsonb).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
});

it('shows the organization\'s defaults in centavos', function () {
    $this->getJson('/api/v1/approval-settings')
        ->assertOk()
        ->assertJsonPath('data.organization', [
            'auto_approve_under_cents' => 500000,
            'ops_approval_under_cents' => 5000000,
            'sla_hours' => 4,
            'variance_threshold_pct' => '15',
            'default_parts_source' => 'supplier_provided',
            'monthly_budget_cents' => 15000000,
            'vat_rate_pct' => '12',
            'misc_fee_flat_cents' => 0,
            'default_labour_rate_cents' => 65000,
        ])
        ->assertJsonPath('data.branch_override', null);
});

it('overrides per branch sparsely, a null clearing back to the default', function () {
    $branch = $this->world->id('mekanikomor-binan');

    $this->putJson("/api/v1/branches/{$branch}/approval-settings", ['sla_hours' => 8, 'misc_fee_flat_cents' => 15000])
        ->assertOk()
        ->assertJsonPath('data.effective.sla_hours', 8)
        ->assertJsonPath('data.effective.misc_fee_flat_cents', 15000)
        ->assertJsonPath('data.effective.auto_approve_under_cents', 500000);

    $this->getJson('/api/v1/approval-settings', ['X-Branch-Id' => $branch])
        ->assertJsonPath('data.branch_override', ['sla_hours' => 8, 'misc_fee_flat_cents' => 15000]);

    $this->putJson("/api/v1/branches/{$branch}/approval-settings", ['sla_hours' => null])
        ->assertOk()->assertJsonPath('data.effective.sla_hours', 4);

    expect(asSystem(fn () => AuditLog::query()->where('entity_type', 'approval_setting')->count()))->toBe(2);
});

it('bills a branch that is not VAT-registered at 0%, whatever the rate', function () {
    $branch = $this->world->id('mekanikomor-binan');
    asSystem(fn () => Branch::query()->whereKey($branch)->update(['is_vat_registered' => false]));

    $id = $this->postJson('/api/v1/work-orders', [
        'vehicle_id' => $this->world->id('veh-001'),
        'branch_id' => $branch,
        'title' => 'Oil change',
        'type' => 'preventive',
        'lines' => [['description' => 'Oil', 'unit_part_rate_cents' => 250000]],
    ])->assertCreated()->assertJsonPath('data.totals.vat_rate_pct', '0')->assertJsonPath('data.totals.tax_total_cents', 0)->json('data.id');

    expect($id)->toBeString();
});

it('lets the organization\'s defaults change only with organization rights', function () {
    $this->putJson('/api/v1/approval-settings', ['vat_rate_pct' => '0'])->assertOk()->assertJsonPath('data.effective.vat_rate_pct', '0');

    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $this->putJson('/api/v1/approval-settings', ['vat_rate_pct' => '12'])->assertForbidden();
    // Their own branch, yes; another, no.
    $this->putJson('/api/v1/branches/'.$this->world->id('samahuzai-binan').'/approval-settings', ['sla_hours' => 2])->assertOk();
    $this->putJson('/api/v1/branches/'.$this->world->id('mekanikomor-binan').'/approval-settings', ['sla_hours' => 2])->assertNotFound();
});

it('keeps settings to staff', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $this->getJson('/api/v1/approval-settings')->assertForbidden();
});
