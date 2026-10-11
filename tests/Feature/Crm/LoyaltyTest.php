<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Memberships, prepaid packages and loyalty points, at Samahuzai-Biñan.
 *
 * Seed: The Essential is ₱1,800 for a large vehicle; the branch is
 * VAT-registered and quotes VAT on top. "Wash Pass" is 5 credits of The
 * Essential for ₱5,000, valid 180 days. The VIP tier is 10% off with priority
 * booking. Nothing is sold and nobody is enrolled.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12T08:30:00+08:00'));
    Mail::fake();
    $this->world = World::build();
    $this->walkIn = $this->world->id('walk-in');
    $this->essential = $this->world->id('detailing:package:essential');
    $this->plan = $this->world->id('crm:plan:wash-pass');
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
});

/** A finished, signed-off Essential (large) for the given customer. */
function finishedWash(string $date, string $start, ?string $accountId = null, string $packageId = ''): string
{
    $id = test()->postJson('/api/v1/detailing/bookings', [
        'customer_account_id' => $accountId ?? test()->walkIn,
        'package_id' => $packageId === '' ? test()->essential : $packageId,
        'size_class' => 'large',
        'date' => $date,
        'start_time' => $start,
        'status' => 'confirmed',
        'vehicle_label' => '2019 Toyota Fortuner',
    ])->assertCreated()->json('data.id');
    test()->travelTo(CarbonImmutable::parse("{$date}T{$start}:00+08:00"));
    test()->postJson("/api/v1/detailing/bookings/{$id}/status", ['status' => 'on_going', 'inspection_override_reason' => 'Regular customer'])->assertOk();
    test()->travel(90)->minutes();
    test()->postJson("/api/v1/detailing/bookings/{$id}/status", ['status' => 'completed'])->assertOk();
    test()->postJson("/api/v1/detailing/bookings/{$id}/sign-off", ['passed' => true])->assertOk();

    return $id;
}

/** @param array<string, mixed> $body */
function sellPass(array $body = [], string $key = 'sale-1'): TestResponse
{
    return test()->postJson('/api/v1/crm/prepaid-packages', $body + ['customer_account_id' => test()->walkIn, 'plan_id' => test()->plan, 'payment_method' => 'cash'], ['Idempotency-Key' => $key]);
}

/** Every line of the journal entries of one event, as [account code, debit, credit]. */
function journalOf(string $event): array
{
    Sanctum::actingAs(test()->world->user('owner@mekanikomore.ph'));
    $entries = test()->getJson("/api/v1/ledger/journal?event={$event}&per_page=100")->assertOk()->json('data');
    Sanctum::actingAs(test()->world->user('manager.samahuzai@mekanikomore.ph'));

    return array_map(fn (array $entry): array => array_map(fn (array $line): array => [$line['account_code'], $line['debit_cents'], $line['credit_cents']], $entry['lines']), $entries);
}

it('books a prepaid package to Unearned Revenue when sold, and earns it credit by credit', function () {
    $sold = sellPass()->assertCreated()->json('data');
    expect($sold)->toMatchArray([
        'reference' => 'PP-2026-0001', 'plan_name' => 'Wash Pass (5 × The Essential)', 'price_cents' => 500_000,
        'credits_total' => 5, 'credits_used' => 0, 'credits_left' => 5, 'unearned_cents' => 500_000,
        'sold_on' => '2026-10-12', 'expires_on' => '2027-04-10', 'usable' => true, 'payment_method' => 'cash', 'sold_by_name' => 'Liza Samonte',
    ]);
    // A retried click replays the sale; it does not sell a second package.
    expect(sellPass()->json('data.id'))->toBe($sold['id']);

    // Sale: Dr Cash on Hand / Cr Unearned Revenue. Nothing is earned yet.
    expect(journalOf('prepaid_sold'))->toBe([[['1000', 500_000, 0], ['2400', 0, 500_000]]]);

    // The balance the counter sees when the customer books.
    $benefits = $this->getJson("/api/v1/crm/customers/{$this->walkIn}/benefits")->assertOk()->json('data');
    expect($benefits['packages'])->toHaveCount(1)->and($benefits['packages'][0]['credits_left'])->toBe(5)->and($benefits['membership'])->toBeNull();

    // A finished, signed-off wash is settled with one credit instead of an invoice.
    $booking = finishedWash('2026-10-13', '09:00');
    expect($this->getJson("/api/v1/detailing/bookings/{$booking}")->json('data.next'))->toMatchArray(['can_invoice' => true, 'can_redeem' => true]);
    $redeemed = $this->postJson("/api/v1/crm/prepaid-packages/{$sold['id']}/redeem", ['detailing_booking_id' => $booking], ['Idempotency-Key' => 'redeem-1'])->assertCreated()->json('data');
    expect($redeemed)->toMatchArray(['credits_used' => 1, 'credits_left' => 4, 'unearned_cents' => 400_000])
        ->and($redeemed['redemption'])->toMatchArray(['detailing_booking_id' => $booking, 'amount_cents' => 100_000, 'redeemed_on' => '2026-10-13']);

    // Redemption: Dr Unearned Revenue / Cr Sales – Detailing, for one fifth of the price.
    expect(journalOf('prepaid_redeemed'))->toBe([[['2400', 100_000, 0], ['4020', 0, 100_000]]]);

    $job = $this->getJson("/api/v1/detailing/bookings/{$booking}")->assertOk()->json('data');
    expect($job['prepaid'])->toMatchArray(['reference' => 'PP-2026-0001', 'amount_cents' => 100_000])
        ->and($job['next'])->toMatchArray(['can_invoice' => false, 'can_check_out' => false, 'can_redeem' => false, 'locked_by_invoice' => true]);
    // Settled is settled: no invoice, no second credit, no going back to "on going".
    $this->postJson("/api/v1/detailing/bookings/{$booking}/invoice", [], ['Idempotency-Key' => 'inv'])->assertStatus(409);
    $this->postJson("/api/v1/crm/prepaid-packages/{$sold['id']}/redeem", ['detailing_booking_id' => $booking], ['Idempotency-Key' => 'redeem-2'])->assertStatus(409)->assertJsonPath('error.details.reason', 'already_settled');
    $this->postJson("/api/v1/detailing/bookings/{$booking}/status", ['status' => 'on_going'])->assertStatus(409);

    // What the packages still owe is what Unearned Revenue holds.
    $list = $this->getJson('/api/v1/crm/prepaid-packages')->assertOk()->json();
    expect($list['summary'])->toBe(['sold' => 1, 'sold_cents' => 500_000, 'unearned_cents' => 400_000])
        ->and($list['data'][0]['customer_name'])->not->toBeNull();
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $unearned = collect($this->getJson('/api/v1/ledger/accounts?as_of=2026-10-31')->assertOk()->json('data'))->firstWhere('code', '2400');
    expect($unearned['balance_cents'])->toBe(400_000);
});

it('refuses a sale or a redemption that does not add up', function () {
    sellPass(['payment_method' => 'gcash'], 'no-ref')->assertStatus(422)->assertJsonPath('error.details.fields.payment_reference.0', 'A GCash payment needs its reference number.');
    sellPass(['plan_id' => $this->world->id('crm:tier:vip')], 'not-a-plan')->assertStatus(422);
    sellPass(['customer_account_id' => $this->world->id('fc-bayani')], 'suspended')->assertStatus(409);
    $pass = sellPass(['payment_method' => 'gcash', 'payment_reference' => 'GC-778812'], 'ok')->assertCreated()->json('data.id');

    $redeem = fn (string $booking, string $key): TestResponse => $this->postJson("/api/v1/crm/prepaid-packages/{$pass}/redeem", ['detailing_booking_id' => $booking], ['Idempotency-Key' => $key]);

    // Not finished and signed off yet.
    $open = $this->postJson('/api/v1/detailing/bookings', ['customer_account_id' => $this->walkIn, 'package_id' => $this->essential, 'size_class' => 'large', 'date' => '2026-10-22', 'start_time' => '13:00'])->assertCreated()->json('data.id');
    $redeem($open, 'r1')->assertStatus(409)->assertJsonPath('error.details.reason', 'not_billable');
    // Another customer's job, and a job of a package the pass does not cover.
    $redeem(finishedWash('2026-10-20', '13:00', $this->world->id('fc-actimed')), 'r2')->assertStatus(422);
    $redeem(finishedWash('2026-10-21', '13:00', null, $this->world->id('detailing:package:executive')), 'r3')->assertStatus(409)->assertJsonPath('error.details.reason', 'wrong_package');
    // Past its 180 days.
    $late = finishedWash('2027-04-12', '09:00');
    $redeem($late, 'r4')->assertStatus(409)->assertJsonPath('error.details.reason', 'expired');

    // Selling and redeeming is counter work; setting plans up is the manager's. A technician has no CRM.
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $this->getJson('/api/v1/crm/prepaid-plans')->assertOk();
    $this->postJson('/api/v1/crm/prepaid-plans', ['name' => 'Ten washes', 'price_cents' => 900_000, 'credits' => 10])->assertForbidden();
    Sanctum::actingAs($this->world->user('bay@mekanikomore.ph'));
    $this->getJson('/api/v1/crm/prepaid-packages')->assertForbidden();
});

it('takes a member\'s discount off a detailing invoice, and earns and returns loyalty points', function () {
    $tiers = collect($this->getJson('/api/v1/crm/tiers')->assertOk()->json('data'))->keyBy('name');
    expect($tiers['VIP'])->toMatchArray(['discount_pct' => '10.00', 'priority_booking' => true, 'members' => 0, 'perks' => 'Complimentary coffee in the lounge']);

    $membership = $this->postJson("/api/v1/crm/customers/{$this->walkIn}/membership", ['tier_id' => $tiers['VIP']['id']])->assertCreated()->json('data');
    expect($membership)->toMatchArray(['tier_name' => 'VIP', 'started_on' => '2026-10-12', 'status' => 'active', 'in_force' => true]);
    expect($this->getJson("/api/v1/crm/customers/{$this->walkIn}/benefits")->json('data.membership'))->toMatchArray(['discount_pct' => '10.00', 'priority_booking' => true]);
    $this->putJson('/api/v1/crm/settings', ['loyalty_enabled' => true])->assertOk();

    // ₱1,800 less 10% is ₱1,620; with 12% VAT on top, ₱1,814.40.
    $booking = finishedWash('2026-10-13', '09:00');
    $checkout = $this->postJson("/api/v1/detailing/bookings/{$booking}/checkout", ['method' => 'cash'], ['Idempotency-Key' => 'counter'])->assertCreated()->json('data');
    expect($checkout['invoice']['totals'])->toMatchArray(['discount_total_cents' => 18_000, 'total_due_cents' => 181_440])
        ->and($checkout['invoice']['lines'][0]['discount_cents'])->toBe(18_000)
        ->and($checkout['invoice']['notes'])->toContain('VIP member: 10% off.')
        ->and($checkout['invoice']['status'])->toBe('paid');

    // One point per ₱100 paid, once the payment is in.
    expect($this->getJson("/api/v1/crm/customers/{$this->walkIn}/benefits")->json('data.points'))->toBe(18);
    $this->postJson("/api/v1/crm/customers/{$this->walkIn}/points", ['points' => -15, 'reason' => 'redeemed', 'note' => 'Free air freshener'], ['Idempotency-Key' => 'spend'])->assertCreated()->assertJsonPath('data.points', 3);
    $this->postJson("/api/v1/crm/customers/{$this->walkIn}/points", ['points' => -4, 'reason' => 'redeemed'], ['Idempotency-Key' => 'overspend'])->assertStatus(409)->assertJsonPath('error.details.reason', 'insufficient_points');

    // A voided payment takes back the points it earned, even those already spent: the customer then owes them.
    $this->postJson("/api/v1/payments/{$checkout['payment']['id']}/void", ['reason' => 'Wrong tender'])->assertOk();
    expect($this->getJson("/api/v1/crm/customers/{$this->walkIn}/benefits")->json('data.points'))->toBe(-15);

    // A new tier replaces the old one; ending it leaves the customer with none, and the next bill at full price.
    $partner = $this->postJson("/api/v1/crm/customers/{$this->walkIn}/membership", ['tier_id' => $tiers['Fleet Partner']['id']])->assertCreated()->json('data');
    expect(collect($this->getJson('/api/v1/crm/tiers')->json('data'))->pluck('members', 'name')->all())->toBe(['VIP' => 0, 'Fleet Partner' => 1]);
    $this->deleteJson("/api/v1/crm/customers/{$this->walkIn}/membership/{$partner['id']}")->assertOk()->assertJsonPath('data.in_force', false);
    $this->deleteJson('/api/v1/crm/customers/'.$this->world->id('fc-actimed')."/membership/{$partner['id']}")->assertNotFound();
    $plain = finishedWash('2026-10-23', '13:00');
    expect($this->postJson("/api/v1/detailing/bookings/{$plain}/invoice", [], ['Idempotency-Key' => 'plain'])->assertCreated()->json('data.totals.discount_total_cents'))->toBe(0);
});
