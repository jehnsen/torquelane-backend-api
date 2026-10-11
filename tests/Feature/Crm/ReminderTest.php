<?php

declare(strict_types=1);

use App\Mail\CrmMessageMail;
use App\Models\Message;
use App\Models\MessageSuppression;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Service reminders, end to end: the run builds candidates from what is on
 * record, and each goes through the one gate (consent, opt-out, cooldown,
 * quiet hours) into the message log. The candidate rules themselves are
 * pinned in tests/Unit/Domain/Crm.
 *
 * Seed (2026-10-08): Actimed's NBA 4821 (veh-001) has several maintenance
 * items due or overdue; Actimed, Northwind and Sagrada agreed to service
 * reminders when their records were imported, none to marketing; each has a
 * primary contact who takes reminders by email.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    config(['app.frontend_url' => 'https://app.torquelane.test']);
    Mail::fake();
    $this->world = World::build();
    $this->vehicle = $this->world->id('veh-001');
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
});

/** @return Collection<int, Message> */
function remindersAbout(string $vehicleId)
{
    return asSystem(fn () => Message::query()->where('source_type', 'reminder')->where('subject_type', 'vehicle')->where('subject_id', $vehicleId)->orderBy('created_at')->get());
}

/** The token inside a sent mail's link of the given kind (`r`, `unsubscribe`). */
function linkToken(string $body, string $path): string
{
    preg_match("~https://app\\.torquelane\\.test/{$path}/([0-9A-Za-z]{40})~", $body, $match);

    return $match[1] ?? '';
}

it('sends a due vehicle exactly one consented reminder, with a booking link that works', function () {
    $run = $this->postJson('/api/v1/crm/reminders/run')->assertOk()->json('data');
    expect($run['candidates'])->toBeGreaterThan(0)->and($run['queued'])->toBeGreaterThan(0);

    // One message for the vehicle, however many of its items are due.
    $messages = remindersAbout($this->vehicle);
    expect($messages)->toHaveCount(1);
    $message = $messages[0];
    expect($message->status)->toBe('sent')
        ->and($message->purpose->value)->toBe('service_reminder')
        ->and($message->customer_account_id)->toBe($this->world->id('fc-actimed'))
        ->and($message->dedupe_key)->toBe("pms_due:{$this->vehicle}")
        ->and($message->subject)->toContain('NBA 4821')
        ->and($message->body)->toContain('NBA 4821')->toContain('https://app.torquelane.test/r/')->toContain('https://app.torquelane.test/unsubscribe/');

    $mail = null;
    Mail::assertSent(CrmMessageMail::class, function (CrmMessageMail $sent) use ($message, &$mail): bool {
        if ($sent->messageId !== $message->id) {
            return false;
        }
        $mail = $sent;

        return $sent->hasTo($message->to_address);
    });

    // Running it again the same day, and the next morning, sends nothing more about that vehicle.
    $again = $this->postJson('/api/v1/crm/reminders/run')->assertOk()->json('data');
    expect($again['queued'])->toBe(0)->and($again['skipped']['recently_sent'] ?? 0)->toBeGreaterThan(0);
    $this->travel(1)->days();
    $this->artisan('crm:run reminders')->assertSuccessful();
    expect(remindersAbout($this->vehicle))->toHaveCount(1);

    // The link in the message, with no session: what is due, and a request the shop will call back on.
    $token = linkToken($mail->body, 'r');
    expect($token)->not->toBe('');
    app('auth')->forgetGuards();
    $page = $this->getJson("/api/v1/public/crm/reminders/{$token}")->assertOk()->json('data');
    expect($page)->toMatchArray(['headline' => $message->subject, 'requested' => false, 'booking' => ['mode' => 'request', 'branch_slug' => null]])
        ->and($page['vehicle']['plate_number'])->toBe('NBA 4821');
    $this->getJson('/api/v1/public/crm/reminders/'.str_repeat('z', 40))->assertNotFound();

    $this->postJson("/api/v1/public/crm/reminders/{$token}/book", ['preferred_date' => '2026-10-01'])->assertStatus(422);
    $this->postJson("/api/v1/public/crm/reminders/{$token}/book", ['preferred_date' => '2026-10-15', 'note' => 'Mornings, please'])->assertCreated()->assertJsonPath('data.requested', true);
    // Asking twice leaves one request.
    $this->postJson("/api/v1/public/crm/reminders/{$token}/book")->assertCreated();

    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $tasks = $this->getJson('/api/v1/crm/tasks')->assertOk()->json();
    $requests = collect($tasks['data'])->where('kind', 'booking_request')->values();
    expect($requests)->toHaveCount(1)
        ->and($requests[0])->toMatchArray(['title' => 'Booking request: NBA 4821 (Actimed)', 'due_on' => '2026-10-15', 'status' => 'open', 'vehicle_id' => $this->vehicle, 'assignee_id' => null])
        ->and($requests[0]['details'])->toContain('Mornings, please')
        ->and($tasks['summary']['booking_requests'])->toBe(1);
});

it('logs a reminder it may not send as skipped, with the reason, and sends nothing', function () {
    // Northwind withdraws its consent to reminders.
    $northwind = $this->world->id('fc-northwind');
    $this->postJson("/api/v1/customer-accounts/{$northwind}/consents", ['purpose' => 'service_reminders', 'granted' => false, 'channel' => 'phone'], ['Idempotency-Key' => 'withdraw'])->assertCreated();

    $run = $this->postJson('/api/v1/crm/reminders/run')->assertOk()->json('data');
    expect($run['skipped']['no_consent'] ?? 0)->toBeGreaterThan(0);

    $theirs = asSystem(fn () => Message::query()->where('customer_account_id', $northwind)->get());
    expect($theirs)->not->toBeEmpty()
        ->and($theirs->pluck('status')->unique()->all())->toBe(['skipped'])
        ->and($theirs->pluck('skip_reason')->unique()->all())->toBe(['no_consent'])
        ->and($theirs->pluck('token_hash')->filter()->all())->toBe([]);
    Mail::assertNotSent(CrmMessageMail::class, fn (CrmMessageMail $mail): bool => $theirs->pluck('id')->contains($mail->messageId));

    // A refusal is logged once, not once a day.
    $count = $theirs->count();
    $this->travel(1)->days();
    $this->postJson('/api/v1/crm/reminders/run')->assertOk();
    expect(asSystem(fn () => Message::query()->where('customer_account_id', $northwind)->count()))->toBe($count);

    $log = $this->getJson("/api/v1/crm/messages?status=skipped&customer_account_id={$northwind}")->assertOk()->json();
    expect($log['data'][0])->toMatchArray(['status' => 'skipped', 'skip_reason' => 'no_consent', 'customer_name' => 'Northwind Logistics', 'sent_at' => null])
        ->and($log['summary']['skipped'])->toBeGreaterThanOrEqual($count);
});

it('holds a reminder through quiet hours and delivers it when they end', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T22:30:00+08:00'));
    $this->postJson('/api/v1/crm/reminders/run')->assertOk();

    $message = remindersAbout($this->vehicle)->sole();
    expect($message->status)->toBe('queued')->and($message->send_after->toIso8601String())->toBe('2026-10-09T00:00:00+00:00');
    Mail::assertNothingSent();

    // The delivery run leaves it alone until 8:00 the next morning, Manila.
    $this->artisan('crm:run deliver')->assertSuccessful();
    Mail::assertNothingSent();
    $this->travelTo(CarbonImmutable::parse('2026-10-09T08:01:00+08:00'));
    $this->artisan('crm:run deliver')->assertSuccessful();
    expect(remindersAbout($this->vehicle)->sole()->status)->toBe('sent');
    Mail::assertSent(CrmMessageMail::class, fn (CrmMessageMail $mail): bool => $mail->messageId === $message->id);
});

it('stops writing to an address that unsubscribed, and records the consent as withdrawn', function () {
    $this->postJson('/api/v1/crm/reminders/run')->assertOk();
    $message = remindersAbout($this->vehicle)->sole();
    $token = '';
    Mail::assertSent(CrmMessageMail::class, function (CrmMessageMail $mail) use ($message, &$token): bool {
        if ($mail->messageId === $message->id) {
            $token = linkToken($mail->body, 'unsubscribe');
        }

        return true;
    });

    app('auth')->forgetGuards();
    $this->getJson("/api/v1/public/crm/unsubscribe/{$token}")->assertOk()->assertJsonPath('data.unsubscribed', false)->assertJsonPath('data.purpose_label', 'Service reminder');
    $this->postJson("/api/v1/public/crm/unsubscribe/{$token}")->assertCreated()->assertJsonPath('data.unsubscribed', true);
    $this->postJson("/api/v1/public/crm/unsubscribe/{$token}")->assertCreated();
    expect(asSystem(fn () => MessageSuppression::query()->where('address', $message->to_address)->count()))->toBe(1);

    // On the customer's record: reminders withdrawn, by email.
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $actimed = $this->world->id('fc-actimed');
    $decisions = collect($this->getJson("/api/v1/customer-accounts/{$actimed}/consents")->assertOk()->json('data'));
    expect($decisions->where('purpose', 'service_reminders')->where('granted', false)->where('channel', 'email'))->toHaveCount(1);

    // Past the cooldown the vehicle is still due, and still nothing goes to them.
    $this->travel(20)->days();
    Mail::fake();
    $this->postJson('/api/v1/crm/reminders/run')->assertOk();
    $later = remindersAbout($this->vehicle);
    expect($later)->toHaveCount(2)->and($later[1]->status)->toBe('skipped')->and($later[1]->skip_reason)->toBe('no_consent');
    Mail::assertNotSent(CrmMessageMail::class, fn (CrmMessageMail $mail): bool => $mail->hasTo($message->to_address));
});

it('sets up reminder rules and templates, to those who may', function () {
    $rules = collect($this->getJson('/api/v1/crm/reminder-rules')->assertOk()->json('data'))->keyBy('kind');
    expect($rules->keys()->all())->toBe(['pms_due', 'document_expiry', 'detailing_repeat', 'lapsed_customer'])
        ->and($rules['pms_due']['rule'])->toMatchArray(['enabled' => true, 'template_name' => 'Service due', 'lead_days' => null])
        ->and($rules['document_expiry']['rule']['lead_days'])->toBe(30);

    // Switched off, a kind of reminder is not built.
    $this->putJson('/api/v1/crm/reminder-rules/pms_due', ['template_id' => $rules['pms_due']['rule']['template_id'], 'enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    $this->postJson('/api/v1/crm/reminders/run')->assertOk();
    expect(remindersAbout($this->vehicle))->toHaveCount(0);

    // A rule takes a service-reminder template, never a marketing one.
    $promo = $this->world->id('crm:template:rainy-season');
    $this->putJson('/api/v1/crm/reminder-rules/pms_due', ['template_id' => $promo])->assertStatus(422)->assertJsonPath('error.details.fields.template_id.0', 'Pick a service-reminder template.');
    $this->putJson('/api/v1/crm/reminder-rules/nonsense', ['template_id' => $promo])->assertNotFound();

    $made = $this->postJson('/api/v1/crm/templates', ['name' => 'Text nudge', 'channel' => 'sms', 'purpose' => 'service_reminder', 'body' => '{{vehicle}} is {{status}}. Book: {{link}}'])->assertCreated()->json('data');
    expect($made['placeholders'])->toBe(['vehicle', 'status', 'link']);
    $this->postJson('/api/v1/crm/templates', ['name' => 'text NUDGE', 'purpose' => 'marketing', 'body' => 'x'])->assertStatus(422);
    $this->postJson('/api/v1/crm/templates', ['name' => 'No subject', 'channel' => 'email', 'purpose' => 'marketing', 'body' => 'x'])->assertStatus(422)->assertJsonPath('error.details.fields.subject.0', 'An email needs a subject.');
    $this->putJson('/api/v1/crm/templates/'.$rules['document_expiry']['rule']['template_id'], ['purpose' => 'marketing'])->assertStatus(409);
    $this->deleteJson("/api/v1/crm/templates/{$made['id']}")->assertNoContent();

    $this->putJson('/api/v1/crm/settings', ['quiet_start_minutes' => 1200, 'reminder_cooldown_days' => 30])->assertOk()->assertJsonPath('data.quiet_start_minutes', 1200)->assertJsonPath('data.quiet_end_minutes', 480);

    // An advisor reads and works; setting up is the manager's and the owner's. A technician and a customer have no CRM.
    Sanctum::actingAs($this->world->user('advisor@mekanikomore.ph'));
    $this->getJson('/api/v1/crm/reminder-rules')->assertOk();
    $this->putJson('/api/v1/crm/settings', ['lapsed_after_days' => 90])->assertForbidden();
    $this->postJson('/api/v1/crm/reminders/run')->assertForbidden();
    Sanctum::actingAs($this->world->user('bay@mekanikomore.ph'));
    $this->getJson('/api/v1/crm/messages')->assertForbidden();
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));
    $this->getJson('/api/v1/crm/messages')->assertForbidden();
    $this->getJson('/api/v1/crm/customers/'.$this->world->id('fc-northwind'))->assertNotFound();
});
