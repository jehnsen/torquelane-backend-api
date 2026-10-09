<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\MaintenanceState;
use App\Models\MeterReading;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * One order through the whole workflow, as the shop and the customer each
 * see it: raise (draft) → send (numbered) → decide per line → schedule onto a
 * bay → start → record the work → close (PMS clock reset) → collect.
 *
 * Seed facts: veh-001 is Actimed's, 45,600 km read 2026-10-08; Actimed runs
 * on the organization defaults (auto-approve under ₱5,000, operations up to
 * ₱50,000, 12% VAT on top); the work_order series continues at 1656.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

function signIn(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/**
 * Two lines: brake pads (2 × ₱1,800 + 1.5 h × ₱650) and wiper blades
 * (₱650 + 0.25 h × ₱650) — ₱5,387.50 pre-tax, in the operations band.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<JsonResponse>
 */
function raiseOrder(array $overrides = [], ?string $vehicle = null): TestResponse
{
    $world = test()->world;

    return test()->postJson('/api/v1/work-orders', array_replace([
        'vehicle_id' => $vehicle ?? $world->id('veh-001'),
        'title' => 'Brakes and wipers',
        'type' => 'corrective',
        'priority' => 'high',
        'lines' => [
            ['description' => 'Brake pads', 'service_task_id' => $world->id('task:brake-inspection'), 'quantity' => 2, 'unit_part_rate_cents' => 180000, 'labour_hours' => 1.5, 'labour_rate_cents' => 65000, 'urgency' => 'safety_critical'],
            ['description' => 'Wiper blades', 'quantity' => 1, 'unit_part_rate_cents' => 65000, 'labour_hours' => 0.25, 'urgency' => 'optional'],
        ],
    ], $overrides));
}

it('raises an unnumbered draft and prices every line on the server', function () {
    signIn('advisor@mekanikomore.ph');

    raiseOrder()
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.lifecycle_stage', 'draft')
        ->assertJsonPath('data.reference', '')
        ->assertJsonPath('data.display_reference', 'Draft — not yet numbered')
        ->assertJsonPath('data.branch_id', $this->world->id('mekanikomor-binan'))
        ->assertJsonPath('data.lines.0.part_cost_cents', 360000)
        ->assertJsonPath('data.lines.0.labour_cost_cents', 97500)
        // The shop's default rate where none was given.
        ->assertJsonPath('data.lines.1.labour_rate_cents', 65000)
        ->assertJsonPath('data.lines.1.labour_cost_cents', 16250)
        ->assertJsonPath('data.totals', [
            'parts_total_cents' => 425000,
            'labour_total_cents' => 113750,
            'sub_total_cents' => 538750,
            'misc_total_cents' => 0,
            'tax_total_cents' => 64650,
            'vat_rate_pct' => '12',
            'grand_total_cents' => 603400,
        ])
        ->assertJsonPath('data.approval.required_approver', 'operations')
        ->assertJsonPath('data.history.0.status', 'draft')
        ->assertJsonPath('data.history.0.actor_name', 'Divina Lacson');
});

it('takes an order from draft to the vehicle handed back', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder()->assertCreated()->json('data.id');
    [$brakes, $wipers] = $this->getJson("/api/v1/work-orders/{$id}")->json('data.lines.*.id');

    // Sent: numbered now, waiting on the customer.
    $this->postJson("/api/v1/work-orders/{$id}/send")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.reference', 'WO-2026-1656')
        ->assertJsonPath('data.approval_log.0.action', 'sent_for_approval')
        ->assertJsonPath('data.approval_log.0.amount_at_time_cents', 538750)
        ->assertJsonPath('data.approval_log.0.note', 'Quotation sent for 2 lines.');

    // The customer answers two business hours later: skip the brakes (with a reason), do the wipers.
    $this->travel(2)->hours();
    signIn('ops@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => [
        ['line_id' => $brakes, 'decision' => 'declined'],
        ['line_id' => $wipers, 'decision' => 'approved'],
    ]])->assertUnprocessable()->assertJsonPath('error.details.fields', fn (array $f) => isset($f['decisions.0.note']));

    $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => [
        ['line_id' => $brakes, 'decision' => 'declined', 'note' => 'Done at the dealer last week.'],
        ['line_id' => $wipers, 'decision' => 'approved'],
    ]])
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_approved')
        ->assertJsonPath('data.lifecycle_stage', 'approved')
        ->assertJsonPath('data.approval.approval_wait_hours', 2)
        ->assertJsonPath('data.approval.pending_approval_entered_at', null)
        ->assertJsonPath('data.lines.0.decline_reason', 'Done at the dealer last week.')
        ->assertJsonPath('data.lines.1.approved_by_name', 'Marisol Bautista')
        ->assertJsonPath('data.approved_totals.sub_total_cents', 81250)
        // The shop's ids are the shop's business.
        ->assertJsonPath('data.assigned_branch_id', null);

    signIn('advisor@mekanikomore.ph');
    $this->getJson("/api/v1/work-orders/{$id}")->assertJsonPath('data.assigned_branch_id', $this->world->id('mekanikomor-binan'));
    $this->postJson("/api/v1/work-orders/{$id}/schedule", [
        'scheduled_for' => '2026-10-09',
        'scheduled_time' => '09:00',
        'bay_id' => $this->world->id('bay:bay-2'),
    ])->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.bay_id', $this->world->id('bay:bay-2'));

    signIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/work-orders/{$id}/complete", [
        'findings' => 'Blades torn; replaced.',
        'odometer_at_service' => 45700,
        'parts' => [['part_number' => 'WB-22', 'name' => 'Wiper blade set', 'quantity' => 1, 'unit_cost_cents' => 65000]],
        'task_ids' => [$this->world->id('task:air-filter')],
    ])->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.parts.0.unit_cost_cents', 65000);

    $this->postJson("/api/v1/work-orders/{$id}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.lifecycle_stage', 'ready_for_billing')
        ->assertJsonPath('data.completed_on', '2026-10-08');

    // Closing reset the discharged task's clock and recorded the higher odometer.
    $state = asSystem(fn () => MaintenanceState::query()->where('vehicle_id', $this->world->id('veh-001'))->where('service_task_id', $this->world->id('task:air-filter'))->firstOrFail());
    expect((string) $state->last_done_value)->toBe('45700.000')
        ->and($state->last_done_on->toDateString())->toBe('2026-10-08')
        ->and((string) asSystem(fn () => MeterReading::query()->where('vehicle_id', $this->world->id('veh-001'))->where('source', 'work_order')->value('value')))->toBe('45700.000');

    // Phase 7: handing the vehicle back releases it; the job stays to be billed (it completes when its invoice is paid).
    signIn('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/collect")
        ->assertOk()
        ->assertJsonPath('data.lifecycle_stage', 'ready_for_billing')
        ->assertJsonPath('data.collected_at', null);
    $this->postJson("/api/v1/work-orders/{$id}/collect")->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    // Every step wrote its status event and its audit row, in order.
    $order = $this->getJson("/api/v1/work-orders/{$id}")->json('data');
    expect(array_column($order['history'], 'status'))->toBe(['draft', 'pending_approval', 'partially_approved', 'scheduled', 'in_progress', 'closed'])
        ->and(array_column($order['approval_log'], 'action'))->toBe(['sent_for_approval', 'declined', 'approved'])
        ->and(asSystem(fn () => AuditLog::query()->where('entity_id', $id)->orderBy('occurred_at')->orderBy('id')->pluck('action')->all()))
        ->toBe(['created', 'sent_for_approval', 'lines_decided', 'scheduled', 'started', 'work_recorded', 'closed', 'released']);
});

it('auto-approves inside the band, numbered all the same', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder(['lines' => [['description' => 'Wiper blades', 'unit_part_rate_cents' => 65000]]])->json('data.id');

    $this->postJson("/api/v1/work-orders/{$id}/send")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.reference', 'WO-2026-1656')
        ->assertJsonPath('data.lines.0.approved_by_name', 'System (auto-approval)')
        ->assertJsonPath('data.approval_log.0.action', 'auto_approved')
        ->assertJsonPath('data.history.1.actor_name', 'System (auto-approval)');
});

it('never auto-approves for an account whose ceiling is zero', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder(['lines' => [['description' => 'Bulb', 'unit_part_rate_cents' => 15000]]], $this->world->id('veh-151'))->json('data.id');

    $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk()->assertJsonPath('data.status', 'pending_approval');
});

it('reopens a declined quote as a draft that keeps its number', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder()->json('data.id');
    $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk();
    $lines = $this->getJson("/api/v1/work-orders/{$id}")->json('data.lines.*.id');

    signIn('donmiguel@mekanikomor.ph');
    $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => array_map(fn (string $l): array => ['line_id' => $l, 'decision' => 'deferred'], $lines)])
        ->assertOk()->assertJsonPath('data.status', 'declined');

    signIn('advisor@mekanikomore.ph');
    $this->patchJson("/api/v1/work-orders/{$id}", ['notes' => 'Re-quoted with OEM pads.'])
        ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.reference', 'WO-2026-1656');
    $this->putJson("/api/v1/work-orders/{$id}/lines", ['lines' => [['id' => $lines[0], 'description' => 'OEM brake pads', 'quantity' => 2, 'unit_part_rate_cents' => 210000], ['id' => $lines[1], 'description' => 'Wiper blades', 'unit_part_rate_cents' => 65000]]])
        ->assertOk()->assertJsonPath('data.lines.0.part_cost_cents', 420000)->assertJsonPath('data.lines.0.approval_status', 'pending');
    $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk()->assertJsonPath('data.reference', 'WO-2026-1656');
});

it('keeps a line with approval history on the order', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder()->json('data.id');
    $this->postJson("/api/v1/work-orders/{$id}/send");
    $lines = $this->getJson("/api/v1/work-orders/{$id}")->json('data.lines.*.id');
    signIn('donmiguel@mekanikomor.ph');
    $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => array_map(fn (string $l): array => ['line_id' => $l, 'decision' => 'deferred'], $lines)]);

    signIn('advisor@mekanikomore.ph');
    $this->patchJson("/api/v1/work-orders/{$id}", [])->assertOk();
    $this->putJson("/api/v1/work-orders/{$id}/lines", ['lines' => []])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
});

it('decides lines only while the quotation is pending', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder()->json('data.id');
    $line = $this->getJson("/api/v1/work-orders/{$id}")->json('data.lines.0.id');

    signIn('donmiguel@mekanikomor.ph');
    $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => [['line_id' => $line, 'decision' => 'approved']]])
        ->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
});

it('holds approval to the role\'s band', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder(['lines' => [['description' => 'Transmission overhaul', 'unit_part_rate_cents' => 6000000]]])->json('data.id');
    $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk()->assertJsonPath('data.approval.required_approver', 'fleet_manager');
    $line = $this->getJson("/api/v1/work-orders/{$id}")->json('data.lines.0.id');
    $decide = fn () => $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => [['line_id' => $line, 'decision' => 'approved']]]);

    $canApprove = fn () => $this->getJson("/api/v1/work-orders/{$id}")->json('data.approval.can_approve');

    // No approval capability at all.
    expect($canApprove())->toBeFalse();
    $decide()->assertForbidden();
    // Operations: capability, but ₱60,000 is past their ceiling.
    signIn('ops@mekanikomore.ph');
    expect($canApprove())->toBeFalse();
    $decide()->assertForbidden()->assertJsonPath('error.message', 'Approving ₱60,000.00 needs a Fleet Manager.');
    signIn('donmiguel@mekanikomor.ph');
    expect($canApprove())->toBeTrue();
    $decide()->assertOk()->assertJsonPath('data.status', 'approved');
});

it('reports the approval wait in business hours, and the SLA breach, while pending', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder()->json('data.id');
    $this->getJson("/api/v1/work-orders/{$id}")
        ->assertJsonPath('data.approval.waiting_hours', null)
        ->assertJsonPath('data.approval.sla_breached', false);

    $sent = $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk();
    expect($sent->json('data.approval.waiting_hours'))->toEqual(0)
        ->and($sent->json('data.approval.sla_breached'))->toBeFalse();

    // A fortnight later, whatever the SLA, it has been breached.
    $this->travelTo(CarbonImmutable::parse('2026-10-22T10:00:00+08:00'));
    $late = $this->getJson("/api/v1/work-orders/{$id}")->assertOk();
    expect($late->json('data.approval.waiting_hours'))->toBeGreaterThan($late->json('data.approval.sla_hours'))
        ->and($late->json('data.approval.sla_breached'))->toBeTrue();

    // Decided: no longer waiting.
    $lines = $late->json('data.lines.*.id');
    signIn('donmiguel@mekanikomor.ph');
    $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => array_map(fn (string $l): array => ['line_id' => $l, 'decision' => 'approved'], $lines)])
        ->assertOk()
        ->assertJsonPath('data.approval.waiting_hours', null)
        ->assertJsonPath('data.approval.sla_breached', false);
});

it('blocks close-out past the variance threshold until someone with authority re-approves it', function () {
    signIn('advisor@mekanikomore.ph');
    // ₱6,650: past the auto-approve ceiling, so the customer approves it.
    $id = raiseOrder(['lines' => [['description' => 'Oil change', 'unit_part_rate_cents' => 600000, 'labour_hours' => 1]]])->json('data.id');
    $this->postJson("/api/v1/work-orders/{$id}/send");
    $line = $this->getJson("/api/v1/work-orders/{$id}")->json('data.lines.0.id');
    signIn('donmiguel@mekanikomor.ph');
    $this->postJson("/api/v1/work-orders/{$id}/decisions", ['decisions' => [['line_id' => $line, 'decision' => 'approved']]])->assertOk();

    signIn('bay@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/start")->assertOk();
    // Approved ₱6,650; parts fitted ₱8,000 + approved labour ₱650 = ₱8,650 (30% over a 15% threshold).
    $this->postJson("/api/v1/work-orders/{$id}/complete", ['parts' => [['name' => 'Synthetic oil', 'quantity' => 4, 'unit_cost_cents' => 200000]]])->assertOk();

    $this->postJson("/api/v1/work-orders/{$id}/close")
        ->assertStatus(409)
        ->assertJsonPath('error.details.reason', 'variance_exceeded')
        ->assertJsonPath('error.details.actual_cents', 865000)
        ->assertJsonPath('error.details.approved_cents', 665000);
    // A technician cannot wave it through.
    $this->postJson("/api/v1/work-orders/{$id}/close", ['variance_approved' => true])->assertForbidden();

    signIn('owner@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/close", ['variance_approved' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.approval_log.2.action', 'variance_approved')
        ->assertJsonPath('data.approval_log.2.amount_at_time_cents', 865000);
});

it('cancels with a reason, and a terminal order stays terminal', function () {
    signIn('advisor@mekanikomore.ph');
    $id = raiseOrder()->json('data.id');

    $this->postJson("/api/v1/work-orders/{$id}/cancel")->assertUnprocessable();
    $this->postJson("/api/v1/work-orders/{$id}/cancel", ['reason' => 'Customer sold the vehicle.'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Customer sold the vehicle.');
    $this->postJson("/api/v1/work-orders/{$id}/send")
        ->assertStatus(409)->assertJsonPath('error.message', 'A cancelled work order cannot become pending approval.');
});

it('keeps scheduling, collection and the floor on the staff side', function () {
    signIn('donmiguel@mekanikomor.ph');
    $id = raiseOrder(['lines' => [['description' => 'Wiper blades', 'unit_part_rate_cents' => 65000]]])->assertCreated()
        // A portal request waits to be taken into a branch.
        ->assertJsonPath('data.branch_id', null)
        ->json('data.id');
    $this->postJson("/api/v1/work-orders/{$id}/send")->assertOk()->assertJsonPath('data.status', 'approved');

    $this->postJson("/api/v1/work-orders/{$id}/schedule", ['scheduled_for' => '2026-10-09', 'scheduled_time' => '09:00', 'bay_id' => $this->world->id('bay:bay-1')])->assertForbidden();
    $this->getJson('/api/v1/shop/arriving')->assertForbidden();

    // Staff book it: the bay takes it into its branch.
    signIn('advisor@mekanikomore.ph');
    $this->postJson("/api/v1/work-orders/{$id}/schedule", ['scheduled_for' => '2026-10-09', 'scheduled_time' => '09:00', 'bay_id' => $this->world->id('bay:bay-1')])
        ->assertOk()->assertJsonPath('data.branch_id', $this->world->id('mekanikomor-binan'))->assertJsonPath('data.assigned_branch_id', $this->world->id('mekanikomor-binan'));
});

it('refuses repair work in a branch without the repair module', function () {
    signIn('owner@mekanikomore.ph');

    raiseOrder(['branch_id' => $this->world->id('samahuzai-binan')])->assertForbidden()->assertJsonPath('error.code', 'module_disabled');
});
