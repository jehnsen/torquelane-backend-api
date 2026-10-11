<?php

declare(strict_types=1);

use App\Mail\CrmMessageMail;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Segments, campaigns and post-payment feedback.
 *
 * Seed: three active company accounts (Actimed, Northwind, Sagrada), none of
 * which agreed to marketing; a "Company accounts" segment; a marketing
 * template; a draft campaign. Actimed owes INV-2026-0003 (₱2,088.80).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    config(['app.frontend_url' => 'https://app.torquelane.test']);
    Mail::fake();
    $this->world = World::build();
    $this->actimed = $this->world->id('fc-actimed');
    $this->segment = $this->world->id('crm:segment:companies');
    $this->template = $this->world->id('crm:template:rainy-season');
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
});

function agreeToMarketing(string $accountId): void
{
    test()->postJson("/api/v1/customer-accounts/{$accountId}/consents", ['purpose' => 'marketing', 'granted' => true, 'channel' => 'email'], ['Idempotency-Key' => "mkt-{$accountId}"])->assertCreated();
}

it('sends a campaign only to customers who agreed to marketing, and logs everyone else as skipped', function () {
    agreeToMarketing($this->actimed);
    $members = $this->getJson("/api/v1/crm/segments/{$this->segment}/members")->assertOk()->json();
    expect(array_column($members['data'], 'display_name'))->toBe(['Actimed', 'Northwind Logistics', 'Sagrada Medical Transport']);

    $campaign = $this->postJson('/api/v1/crm/campaigns', ['name' => 'October promo', 'segment_id' => $this->segment, 'template_id' => $this->template])->assertCreated()->json('data');
    expect($campaign)->toMatchArray(['status' => 'draft', 'segment_name' => 'Company accounts', 'template_name' => 'Rainy season promo', 'can_schedule' => true]);
    // Nothing goes until it is scheduled.
    $this->artisan('crm:run campaigns')->assertSuccessful();
    Mail::assertNothingSent();

    $this->postJson("/api/v1/crm/campaigns/{$campaign['id']}/status", ['status' => 'scheduled'])->assertOk()->assertJsonPath('data.status', 'scheduled');
    $this->putJson("/api/v1/crm/campaigns/{$campaign['id']}", ['name' => 'Too late'])->assertStatus(409);
    $this->artisan('crm:run campaigns')->assertSuccessful();

    $done = $this->getJson("/api/v1/crm/campaigns/{$campaign['id']}")->assertOk()->json('data');
    expect($done['status'])->toBe('sent')
        ->and($done['progress'])->toBe(['audience' => 3, 'queued' => 0, 'sent' => 1, 'failed' => 0, 'skipped' => 2, 'skipped_by_reason' => ['no_consent' => 2], 'remaining' => 0]);

    Mail::assertSentCount(1);
    Mail::assertSent(CrmMessageMail::class, fn (CrmMessageMail $mail): bool => $mail->subjectLine === 'Rainy-season care from MekanikoMoR' && str_contains($mail->body, 'https://app.torquelane.test/unsubscribe/'));

    // Fully logged: who got it, who did not, and why.
    $log = collect($this->getJson("/api/v1/crm/messages?source_type=campaign&source_id={$campaign['id']}")->assertOk()->json('data'))->keyBy('customer_name');
    expect($log->keys()->sort()->values()->all())->toBe(['Actimed', 'Northwind Logistics', 'Sagrada Medical Transport'])
        ->and($log['Actimed'])->toMatchArray(['status' => 'sent', 'purpose' => 'marketing', 'skip_reason' => null])
        ->and($log['Northwind Logistics'])->toMatchArray(['status' => 'skipped', 'skip_reason' => 'no_consent', 'sent_at' => null]);

    // A second run sends nothing more; a campaign takes a marketing template only.
    $this->artisan('crm:run campaigns')->assertSuccessful();
    Mail::assertSentCount(1);
    $this->postJson('/api/v1/crm/campaigns', ['name' => 'Wrong kind', 'segment_id' => $this->segment, 'template_id' => $this->world->id('crm:template:service-due')])
        ->assertStatus(422)->assertJsonPath('error.details.fields.template_id.0', 'Pick an active marketing template.');
});

it('throttles a campaign to its hourly limit and picks up where it left off', function () {
    agreeToMarketing($this->actimed);
    agreeToMarketing($this->world->id('fc-northwind'));
    $id = $this->postJson('/api/v1/crm/campaigns', ['name' => 'Slow', 'segment_id' => $this->segment, 'template_id' => $this->template, 'hourly_limit' => 1, 'scheduled_at' => '2026-10-08T12:00:00+08:00'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/crm/campaigns/{$id}/status", ['status' => 'scheduled'])->assertOk();
    $progress = fn (): array => $this->getJson("/api/v1/crm/campaigns/{$id}")->json('data');

    // Not before its time.
    $this->artisan('crm:run campaigns')->assertSuccessful();
    expect($progress()['status'])->toBe('scheduled');

    $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00+08:00'));
    $this->artisan('crm:run campaigns')->assertSuccessful();
    expect($progress())->toMatchArray(['status' => 'sending'])->and($progress()['progress'])->toMatchArray(['audience' => 3, 'sent' => 1, 'remaining' => 2]);
    // Within the hour: nothing more.
    $this->travel(30)->minutes();
    $this->artisan('crm:run campaigns')->assertSuccessful();
    expect($progress()['progress']['sent'])->toBe(1);

    // A customer who joins the segment mid-flight is not added: the audience was fixed at the start.
    $this->travel(31)->minutes();
    $this->artisan('crm:run campaigns')->assertSuccessful();
    expect($progress()['progress'])->toMatchArray(['sent' => 2, 'remaining' => 1]);
    $this->travel(61)->minutes();
    $this->artisan('crm:run campaigns')->assertSuccessful();
    expect($progress())->toMatchArray(['status' => 'sent'])->and($progress()['progress'])->toMatchArray(['sent' => 2, 'skipped' => 1, 'remaining' => 0]);

    // Cancelling stops one that has not finished.
    $other = $this->postJson('/api/v1/crm/campaigns', ['name' => 'Never', 'segment_id' => $this->segment, 'template_id' => $this->template])->json('data.id');
    $this->postJson("/api/v1/crm/campaigns/{$other}/status", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.can_cancel', false);
    $this->postJson("/api/v1/crm/campaigns/{$other}/status", ['status' => 'scheduled'])->assertStatus(409);
});

it('works out a segment when it is read, from spend, visits, vehicles, tier, branch, type and tag', function () {
    $preview = fn (array $filters): array => array_column($this->postJson('/api/v1/crm/segments/preview', ['filters' => $filters])->assertOk()->json('data'), 'display_name');
    $everyone = $preview([]);
    expect($everyone)->toContain('Actimed', 'Northwind Logistics')
        // A suspended account is never in a segment.
        ->not->toContain('Bayani Construction')
        ->and($preview(['account_type' => 'company']))->toBe(['Actimed', 'Northwind Logistics', 'Sagrada Medical Transport'])
        // Actimed has paid ₱3,846.08; nobody else has paid anything.
        ->and($preview(['min_spend_cents' => 384_608]))->toBe(['Actimed'])
        ->and($preview(['min_spend_cents' => 384_609]))->toBe([])
        ->and($preview(['account_type' => 'company', 'max_spend_cents' => 0]))->toBe(['Northwind Logistics', 'Sagrada Medical Transport'])
        ->and($preview(['tier_ids' => [$this->world->id('crm:tier:vip')]]))->toBe([]);

    $made = $this->postJson('/api/v1/crm/segments', ['name' => 'Paying companies', 'filters' => ['account_type' => 'company', 'min_spend_cents' => 1, 'makes' => []]])->assertCreated()->json('data');
    expect($made['filters'])->toBe(['min_spend_cents' => 1, 'account_type' => 'company']);
    $this->postJson('/api/v1/crm/segments', ['name' => 'paying COMPANIES'])->assertStatus(422);
    $listed = collect($this->getJson('/api/v1/crm/segments')->assertOk()->json('data'))->pluck('size', 'name');
    expect($listed['Paying companies'])->toBe(1)->and($listed['Company accounts'])->toBe(3);

    // A segment a campaign uses stays; one nothing uses can go.
    $this->deleteJson("/api/v1/crm/segments/{$this->segment}")->assertStatus(409);
    $this->deleteJson("/api/v1/crm/segments/{$made['id']}")->assertNoContent();
});

it('asks for feedback once an invoice is paid in full, and scores the answer', function () {
    $invoice = $this->world->id('invoice:actimed-current');
    $pay = fn (int $cents, string $key) => $this->postJson('/api/v1/payments', ['customer_account_id' => $this->actimed, 'branch_id' => $this->world->id('mekanikomor-binan'), 'method' => 'cash', 'amount_cents' => $cents, 'allocations' => [['invoice_id' => $invoice, 'amount_cents' => $cents]]], ['Idempotency-Key' => $key])->assertCreated();

    // Part-paid: not yet.
    $pay(100_000, 'part');
    Mail::assertNothingSent();
    $pay(108_880, 'rest');

    $message = asSystem(fn () => Message::query()->where('source_type', 'feedback')->sole());
    expect($message)->status->toBe('sent')->purpose->value->toBe('feedback')->subject->toBe('How did we do?')
        ->and($message->body)->toContain('INV-2026-0003')->toContain('https://app.torquelane.test/feedback/');
    preg_match('~/feedback/([0-9A-Za-z]{40})~', $message->body, $match);
    $token = $match[1];

    app('auth')->forgetGuards();
    $this->getJson("/api/v1/public/crm/feedback/{$token}")->assertOk()->assertJsonPath('data.answered', false)->assertJsonPath('data.customer_name', 'Actimed');
    $this->postJson("/api/v1/public/crm/feedback/{$token}", ['score' => 11])->assertStatus(422);
    $this->postJson("/api/v1/public/crm/feedback/{$token}", ['score' => 9, 'comment' => 'Quick and tidy.'])->assertCreated()->assertJsonPath('data.band', 'promoter');
    // Answered once: a second answer changes nothing.
    $this->postJson("/api/v1/public/crm/feedback/{$token}", ['score' => 2])->assertCreated()->assertJsonPath('data.score', 9);

    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $feedback = $this->getJson('/api/v1/crm/feedback')->assertOk()->json();
    expect($feedback['summary'])->toBe(['responses' => 1, 'promoters' => 1, 'passives' => 0, 'detractors' => 0, 'nps' => 100, 'requested' => 1])
        ->and($feedback['data'][0])->toMatchArray(['score' => 9, 'band' => 'promoter', 'comment' => 'Quick and tidy.', 'invoice_id' => $invoice, 'customer_name' => 'Actimed']);

    // Switched off, a payment asks for nothing; and a customer who did not agree is not asked.
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $northwind = $this->world->id('fc-northwind');
    $this->postJson("/api/v1/customer-accounts/{$northwind}/consents", ['purpose' => 'service_reminders', 'granted' => false, 'channel' => 'phone'], ['Idempotency-Key' => 'w'])->assertCreated();
    $theirs = $this->world->id('invoice:northwind');
    $this->postJson('/api/v1/payments', ['customer_account_id' => $northwind, 'branch_id' => $this->world->id('mekanikomor-binan'), 'method' => 'cash', 'amount_cents' => 527_520, 'allocations' => [['invoice_id' => $theirs, 'amount_cents' => 527_520]]], ['Idempotency-Key' => 'nw'])->assertCreated();
    expect(asSystem(fn () => Message::query()->where('source_type', 'feedback')->where('customer_account_id', $northwind)->sole()))->status->toBe('skipped')->skip_reason->toBe('no_consent');
    expect($this->getJson('/api/v1/crm/feedback')->json('summary.requested'))->toBe(1);
});
