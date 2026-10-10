<?php

declare(strict_types=1);

use App\Models\DetailingBookingSlot;
use App\Models\JournalLine;
use App\Models\StockMove;
use App\Models\Technician;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * A booking's whole life over HTTP at Samahuzai-Biñan: book → inspect → job →
 * QC → invoice → paid, with the package's materials leaving the shelf.
 *
 * Seed: pool of 2, closing 5:00 PM, inspection required; six packages priced
 * per size; The Essential uses 0.05 jug of shampoo (scaled by size) and 2
 * cloths (not scaled). The detailing store holds 4 jugs at ₱850 and 20 cloths
 * at ₱45. The branch is VAT-registered and quotes VAT on top.
 */

beforeEach(function () {
    Storage::fake('documents');
    $this->travelTo(CarbonImmutable::parse('2026-10-12T08:30:00+08:00'));
    $this->world = World::build();
    $this->branch = $this->world->id('samahuzai-binan');
    $this->essential = $this->world->id('detailing:package:essential');
    $this->detailer = asSystem(fn (): Technician => Technician::query()->where('branch_id', $this->branch)->where('name', 'Rico Dimaculangan')->firstOrFail());
});

function detailAs(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/** @param array<string, mixed> $overrides */
function bookEssential(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/detailing/bookings', $overrides + [
        'customer_account_id' => test()->world->id('walk-in'),
        'package_id' => test()->essential,
        'size_class' => 'large',
        'date' => '2026-10-13',
        'start_time' => '09:00',
        'vehicle_label' => '2019 Toyota Fortuner',
    ]);
}

function setStatus(string $bookingId, string $status, array $extra = []): TestResponse
{
    return test()->postJson("/api/v1/detailing/bookings/{$bookingId}/status", ['status' => $status] + $extra);
}

it('takes a booking from the counter through inspection, the job and sign-off to a paid invoice, with its materials consumed', function () {
    detailAs('manager.samahuzai@mekanikomore.ph');

    // --- what is free, as the API sees it
    $starts = collect($this->getJson("/api/v1/detailing/availability?package_id={$this->essential}&size_class=large&date=2026-10-13")->assertOk()->json('data.starts'))->keyBy('time');
    expect($starts['09:00']['available'])->toBeTrue()
        ->and($starts['09:00']['remaining'])->toBe(2)
        ->and($starts['09:00']['ends'])->toBe(['date' => '2026-10-13', 'minutes' => 720, 'label' => '12:00 PM'])
        // 4:00 PM + 3 hours does not fit before 5:00 PM: two slots today, four tomorrow morning.
        ->and($starts['16:00']['spans_days'])->toBeTrue()
        ->and($starts['16:00']['plan'])->toBe([['date' => '2026-10-13', 'slots' => [960, 990]], ['date' => '2026-10-14', 'slots' => [480, 510, 540, 570]]]);

    // --- book
    $booking = bookEssential([
        'detailer_ids' => [$this->detailer->id],
        'addons' => [['addon_id' => $this->world->id('detailing:addon:engine-bay')]],
    ])->assertCreated()->json('data');
    $id = $booking['id'];

    expect($booking['reference'])->toBe('BK-2026-0004')
        ->and($booking['status'])->toBe('pending')
        ->and($booking['package']['price_cents'])->toBe(180_000)
        ->and($booking['addons'][0]['unit_price_cents'])->toBe(70_000)
        ->and($booking['totals'])->toBe(['package_cents' => 180_000, 'addons_cents' => 70_000, 'total_cents' => 250_000])
        ->and($booking['days'][0]['slots'])->toHaveCount(6)
        ->and($booking['finish']['label'])->toBe('12:00 PM')
        ->and($booking['detailers'])->toBe([['id' => $this->detailer->id, 'name' => 'Rico Dimaculangan']])
        ->and($booking['checklist'])->toHaveCount(4)
        ->and($booking['next']['needs_inspection'])->toBeTrue()
        ->and($booking['status_log'])->toHaveCount(1);

    setStatus($id, 'confirmed')->assertOk();

    // --- no start without the check-in inspection
    setStatus($id, 'on_going')->assertStatus(409)->assertJsonPath('error.details.reason', 'inspection_required');

    $inspection = $this->postJson("/api/v1/detailing/bookings/{$id}/inspections", [
        'stage' => 'check_in',
        'overall_rating' => 7,
        'exterior_rating' => 6,
        'exterior_condition' => 'fair',
        'interior_condition' => 'good',
        'notes' => 'Swirls on the bonnet.',
        'damage_points' => [
            ['x_permille' => 480, 'y_permille' => 120, 'kind' => 'scratch', 'note' => 'Light scratch, front bumper'],
            ['x_permille' => 900, 'y_permille' => 610, 'kind' => 'dent', 'note' => 'Door ding, right rear'],
        ],
    ])->assertCreated()->json('data');
    expect($inspection['damage_points'])->toHaveCount(2)->and($inspection['damage_points'][1]['kind'])->toBe('dent');

    $withPhoto = $this->post("/api/v1/detailing/bookings/{$id}/inspections/{$inspection['id']}/photos", [
        'file' => UploadedFile::fake()->image('front.jpg', 800, 600),
        'phase' => 'before',
        'caption' => 'Front, on arrival',
    ])->assertCreated()->json('data');
    expect($withPhoto['photos'])->toHaveCount(1)->and($withPhoto['photos'][0]['phase'])->toBe('before');
    $this->getJson("/api/v1/documents/{$withPhoto['photos'][0]['document_id']}/download")->assertOk();

    // --- the job
    $this->travelTo(CarbonImmutable::parse('2026-10-13T09:05:00+08:00'));
    $started = setStatus($id, 'on_going')->assertOk()->json('data');
    expect($started['started_at'])->toBe('2026-10-13T01:05:00Z')->and($started['next']['needs_inspection'])->toBeFalse();

    $ticked = $this->postJson("/api/v1/detailing/bookings/{$id}/checklist", ['index' => 0, 'done' => true])->assertOk()->json('data');
    expect($ticked['checklist_progress'])->toBe(['done' => 1, 'total' => 4])->and($ticked['checklist'][0]['done_by_name'])->toBe('Liza Samonte');

    // --- finished early, at 10:40: the 10:30 bucket onward is given back, and the materials leave the shelf
    $this->travelTo(CarbonImmutable::parse('2026-10-13T10:40:00+08:00'));
    $done = setStatus($id, 'completed')->assertOk()->json('data');
    expect($done['completed_at'])->toBe('2026-10-13T02:40:00Z')
        ->and($done['finish'])->toBe(['date' => '2026-10-13', 'minutes' => 640, 'label' => '10:40 AM', 'actual' => true])
        ->and(collect($done['days'][0]['slots'])->where('released', true)->pluck('slot')->all())->toBe([630, 660, 690])
        // Large uses 1.25 × 0.05 = 0.0625 → 0.063 jug at ₱850, and 2 cloths at ₱45.
        ->and($done['materials']['cost_cents'])->toBe(14_355)
        ->and($done['margin'])->toBe(['revenue_cents' => 250_000, 'materials_cost_cents' => 14_355, 'margin_cents' => 235_645, 'margin_pct' => '94.3']);

    $moves = asSystem(fn () => StockMove::query()->where('source_type', 'detailing_booking')->where('source_id', $id)->orderBy('item_id')->get());
    expect($moves)->toHaveCount(2)
        ->and($moves->map(fn (StockMove $move): string => $move->move_type->value)->unique()->all())->toBe(['consumption'])
        ->and($moves->map(fn (StockMove $move): string => (string) $move->quantity)->sort()->values()->all())->toBe(['-2.000', '-0.063']);
    $shampoo = collect($this->getJson('/api/v1/items/'.$this->world->id('item:DTL-SHAMPOO-5L'))->json('data.branches'))->firstWhere('branch_id', $this->branch);
    $cloths = collect($this->getJson('/api/v1/items/'.$this->world->id('item:DTL-MF-CLOTH'))->json('data.branches'))->firstWhere('branch_id', $this->branch);
    expect($shampoo['on_hand'])->toBe('3.937')->and($cloths['on_hand'])->toBe('18.000');

    // The freed half hours can be booked again; the hour still held cannot.
    bookEssential(['start_time' => '10:30', 'detailer_ids' => [$this->detailer->id]])->assertCreated();

    // --- quality control comes before the bill, and is never the detailer's own
    $this->postJson("/api/v1/detailing/bookings/{$id}/invoice", [], ['Idempotency-Key' => 'early'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    asSystem(fn () => Technician::query()->whereKey($this->detailer->id)->update(['user_id' => $this->world->id('manager.samahuzai@mekanikomore.ph')]));
    expect($this->getJson("/api/v1/detailing/bookings/{$id}")->json('data.next.can_sign_off'))->toBeFalse();
    $this->postJson("/api/v1/detailing/bookings/{$id}/sign-off", ['passed' => true])->assertForbidden()
        ->assertJsonPath('error.message', 'A job is signed off by someone other than the detailer who worked it.');

    detailAs('owner@mekanikomore.ph');
    $this->postJson("/api/v1/detailing/bookings/{$id}/sign-off", ['passed' => false])->assertStatus(422);
    $signed = $this->postJson("/api/v1/detailing/bookings/{$id}/sign-off", ['passed' => true, 'notes' => 'Glass streak-free.'])->assertOk()->json('data');
    expect($signed['qc'])->toMatchArray(['status' => 'passed', 'by_name' => 'Mike Manabat'])
        ->and($signed['next'])->toMatchArray(['can_invoice' => true, 'can_check_out' => true]);

    // --- counter checkout: invoice and payment in one action
    $checkout = $this->postJson("/api/v1/detailing/bookings/{$id}/checkout", ['method' => 'cash'], ['Idempotency-Key' => 'counter-1'])->assertCreated()->json('data');
    expect($checkout['invoice']['status'])->toBe('paid')
        ->and($checkout['invoice']['source'])->toBe('detailing')
        ->and($checkout['invoice']['totals']['total_due_cents'])->toBe(280_000)
        ->and($checkout['invoice']['totals']['vat_amount_cents'])->toBe(30_000)
        ->and(array_column($checkout['invoice']['lines'], 'kind'))->toBe(['detailing', 'addon'])
        ->and($checkout['invoice']['lines'][0]['description'])->toBe('The Essential (Large) — BK-2026-0004')
        ->and($checkout['invoice']['lines'][0]['detailing_booking_id'])->toBe($id)
        ->and($checkout['payment']['amount_cents'])->toBe(280_000)
        ->and($checkout['booking']['invoice'])->toMatchArray(['status' => 'paid', 'balance_cents' => 0])
        ->and($checkout['booking']['next'])->toMatchArray(['can_invoice' => false, 'can_check_out' => false, 'locked_by_invoice' => true]);

    // Replaying the same key answers with the same receipt and takes no second payment.
    $this->postJson("/api/v1/detailing/bookings/{$id}/checkout", ['method' => 'cash'], ['Idempotency-Key' => 'counter-1'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    $this->postJson("/api/v1/detailing/bookings/{$id}/checkout", ['method' => 'cash'], ['Idempotency-Key' => 'counter-2'])->assertStatus(409);

    // The sale is booked to Detailing sales, the materials to consumables cost.
    $credited = asSystem(fn () => JournalLine::query()->where('memo', $checkout['invoice']['number'])->with('account')->get()
        ->mapWithKeys(fn (JournalLine $line): array => [$line->account->code => $line->credit_cents - $line->debit_cents])->all());
    expect($credited['4020'] ?? null)->toBe(250_000)->and($credited['2200'] ?? null)->toBe(30_000)->and($credited['1100'] ?? null)->toBe(-280_000);

    // An invoiced booking stays as it is.
    setStatus($id, 'on_going')->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->putJson("/api/v1/detailing/bookings/{$id}/addons", ['addons' => []])->assertStatus(409);
});

it('refuses a full slot, a busy detailer and a blocked slot in the source\'s words', function () {
    detailAs('manager.samahuzai@mekanikomore.ph');

    // The seeded pool is 2. Two bookings at 9:00 fill it; the second names Rico again.
    bookEssential(['detailer_ids' => [$this->detailer->id]])->assertCreated();
    bookEssential(['detailer_ids' => [$this->detailer->id]])->assertStatus(409)
        ->assertJsonPath('error.details.reason', 'detailer_conflict')
        ->assertJsonPath('error.message', 'Already booked at this time: Rico Dimaculangan. Choose a different detailer or time slot.');
    bookEssential()->assertCreated();
    bookEssential()->assertStatus(409)
        ->assertJsonPath('error.details.reason', 'full')
        ->assertJsonPath('error.message', 'This time slot just filled up. Please choose another.');

    $blocked = $this->postJson('/api/v1/detailing/blocked-slots', ['date' => '2026-10-16', 'slots' => [600, 630], 'label' => 'Team meeting'])->assertCreated()->json('data');
    bookEssential(['date' => '2026-10-16', 'start_time' => '09:00'])->assertStatus(409)->assertJsonPath('error.details.reason', 'blocked');
    $starts = collect($this->getJson("/api/v1/detailing/availability?package_id={$this->essential}&size_class=large&date=2026-10-16")->json('data.starts'))->keyBy('time');
    expect($starts['09:00']['reason'])->toBe('blocked')->and($starts['13:00']['available'])->toBeTrue();

    $this->deleteJson("/api/v1/detailing/blocked-slots/{$blocked[0]['id']}")->assertNoContent();
    $this->deleteJson("/api/v1/detailing/blocked-slots/{$blocked[1]['id']}")->assertNoContent();
    bookEssential(['date' => '2026-10-16', 'start_time' => '09:00'])->assertCreated();
});

it('keeps the pool above what is already booked, and reads a typed duration for the shop', function () {
    detailAs('manager.samahuzai@mekanikomore.ph');

    bookEssential()->assertCreated();
    bookEssential()->assertCreated();

    $this->putJson('/api/v1/detailing/settings', ['detailer_pool_size' => 1])->assertStatus(422)
        ->assertJsonPath('error.details.fields.detailer_pool_size.0', 'Pool size cannot drop below 2 — existing bookings already use that many detailers in one hour.');
    $this->putJson('/api/v1/detailing/settings', ['default_detailers_per_booking' => 3])->assertStatus(422);
    $settings = $this->putJson('/api/v1/detailing/settings', ['detailer_pool_size' => 4, 'closing_time' => '18:00', 'public_booking_enabled' => false])->assertOk()->json('data');
    expect($settings)->toMatchArray(['detailer_pool_size' => 4, 'closing_minutes' => 1080, 'closing_label' => '6:00 PM', 'public_booking_enabled' => false, 'active_detailers' => 2, 'peak_heads' => 2]);

    expect($this->getJson('/api/v1/detailing/duration?text='.urlencode('1 hr 45 mins'))->assertOk()->json('data'))
        ->toMatchArray(['slot_budget' => 4, 'minutes' => 120, 'days' => 0, 'interpreted' => '2 hrs']);
});

it('is staff-only, branch-scoped and module-gated', function () {
    $booking = $this->world->id('detailing:booking:morning');

    // A portal user: the shop's bookings are not theirs to see, even of their own account.
    detailAs('donmiguel@mekanikomor.ph');
    $this->getJson('/api/v1/detailing/bookings')->assertForbidden();
    $this->getJson("/api/v1/detailing/bookings/{$booking}")->assertForbidden();

    // Staff pinned to the repair branch: the detailing branch's records are missing to them.
    detailAs('cashier@mekanikomore.ph');
    $this->getJson("/api/v1/detailing/bookings/{$booking}")->assertNotFound();
    $this->getJson('/api/v1/detailing/bookings')->assertForbidden()->assertJsonPath('error.code', 'module_disabled');

    // A technician works a job card but takes no bookings.
    detailAs('bay@mekanikomore.ph');
    $this->getJson("/api/v1/detailing/bookings/{$booking}")->assertOk();
    bookEssential()->assertForbidden();
    $this->putJson('/api/v1/detailing/settings', ['detailer_pool_size' => 3])->assertForbidden();

    // The owner, with the repair branch selected: detailing is not offered there.
    detailAs('owner@mekanikomore.ph');
    $this->getJson('/api/v1/detailing/bookings', ['X-Branch-Id' => $this->world->id('mekanikomor-binan')])->assertForbidden()->assertJsonPath('error.code', 'module_disabled');
    expect($this->getJson('/api/v1/detailing/bookings')->assertOk()->json('meta.summary.total'))->toBe(3);
});

it('shows the schedule and the monitor as the API lays them out', function () {
    detailAs('manager.samahuzai@mekanikomore.ph');

    $schedule = $this->getJson('/api/v1/detailing/schedule?from=2026-10-12&to=2026-10-14')->assertOk()->json('data');
    $nine = collect($schedule['days'][0]['slots'])->firstWhere('slot', 540);
    expect($schedule['pool'])->toBe(2)
        ->and(array_column($schedule['days'], 'date'))->toBe(['2026-10-12', '2026-10-13', '2026-10-14'])
        ->and(array_column($schedule['bays'], 'name'))->toBe(['Detail Bay 1', 'Detail Bay 2'])
        ->and(array_column($schedule['detailers'], 'name'))->toBe(['Jessa Manalo', 'Rico Dimaculangan'])
        ->and($nine)->toBe(['slot' => 540, 'held' => 1, 'remaining' => 1])
        ->and(array_column($schedule['bookings'], 'reference'))->toBe(['BK-2026-0001', 'BK-2026-0002', 'BK-2026-0003']);

    // Only confirmed work reaches the wall: the pending afternoon job does not.
    $monitor = $this->getJson('/api/v1/detailing/monitor?date=2026-10-12')->assertOk()->json('data');
    expect($monitor['counts'])->toBe(['upcoming' => 1, 'in_progress' => 0, 'done' => 0])
        ->and($monitor['jobs'][0])->toMatchArray(['reference' => 'BK-2026-0001', 'stage' => 'upcoming', 'start_label' => '9:00 AM', 'bay_name' => 'Detail Bay 1', 'detailers' => ['Jessa Manalo']]);

    // The coating runs two days: it shows on its second day too, from opening.
    $second = $this->getJson('/api/v1/detailing/monitor?date=2026-10-15')->assertOk()->json('data.jobs.0');
    expect($second)->toMatchArray(['reference' => 'BK-2026-0003', 'start_label' => '8:00 AM', 'continues_from' => '2026-10-14']);

    expect(asSystem(fn () => DetailingBookingSlot::query()->where('booking_id', $this->world->id('detailing:booking:coating'))->count()))->toBe(32);
});
