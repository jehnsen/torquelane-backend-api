<?php

declare(strict_types=1);

use App\Mail\CrmMessageMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Customer 360, notes and tasks, and the B2B pipeline.
 *
 * Seed: Actimed is served at the repair branch (jobs, two invoices, a part
 * payment) and has three detailing bookings at Samahuzai-Biñan; a pinned
 * note and one open task. The pipeline holds three leads: Laguna Logistics
 * (quote sent, ₱45,000), Southpoint Motors (a dealer, negotiating, ₱28,000)
 * and Biñan Taxi Cooperative (new, ₱12,000). The cashier is pinned to the
 * repair branch.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    Mail::fake();
    $this->world = World::build();
    $this->actimed = $this->world->id('fc-actimed');
});

function crmAs(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

it('shows one customer whole, across every branch, with a timeline built from the records', function () {
    // The cashier works one branch; the customer is the organization's.
    crmAs('cashier@mekanikomore.ph');
    $view = $this->getJson("/api/v1/crm/customers/{$this->actimed}")->assertOk()->json('data');

    expect($view['account'])->toMatchArray(['display_name' => 'Actimed', 'account_type' => 'company', 'status' => 'active', 'is_dealer' => false])
        ->and($view['summary'])->toMatchArray(['paid_cents' => 384_608, 'balance_cents' => 576_912 + 208_880, 'open_tasks' => 1, 'points' => 0])
        ->and($view['summary']['vehicles'])->toBeGreaterThan(0)
        ->and($view['vehicles'])->not->toBeEmpty()
        ->and($view['work_orders'])->not->toBeEmpty()
        ->and(array_column($view['invoices'], 'number'))->toContain('INV-2026-0001', 'INV-2026-0003')
        ->and(array_column($view['payments'], 'number'))->toBe(['PAY-2026-0001'])
        // Bookings at the detailing branch, which the cashier is not pinned to.
        ->and(array_unique(array_column($view['bookings'], 'branch_name')))->toBe(['Samahuzai-Biñan'])
        ->and(array_column($view['bookings'], 'reference'))->toContain('BK-2026-0001')
        ->and(collect($view['consents'])->pluck('granted', 'purpose')->all())->toBe(['service_records' => true, 'service_reminders' => true, 'marketing' => null, 'vehicle_history_sharing' => null])
        ->and($view['notes'][0])->toMatchArray(['pinned' => true, 'author_name' => 'Mike Manabat'])
        ->and($view['tasks'][0])->toMatchArray(['title' => 'Review Actimed\'s Q4 maintenance plan', 'status' => 'open', 'due_on' => '2026-10-15', 'overdue' => false])
        ->and($view['membership'])->toBeNull()
        ->and($view['packages'])->toBe([]);

    // The timeline: every kind of record, newest first.
    $kinds = array_values(array_unique(array_column($view['timeline'], 'kind')));
    expect($kinds)->toContain('work_order', 'invoice', 'payment', 'booking', 'note', 'task');
    $times = array_column($view['timeline'], 'at');
    $sorted = $times;
    rsort($sorted);
    expect($times)->toBe($sorted)
        ->and(collect($view['timeline'])->firstWhere('kind', 'invoice'))->toHaveKeys(['title', 'href', 'branch_name', 'amount_cents']);

    // The list: what each customer has paid, when they were last in, and their open tasks.
    $list = $this->getJson('/api/v1/crm/customers?q=acti')->assertOk()->json();
    expect($list['meta']['total'])->toBe(1)->and($list['data'][0])->toMatchArray(['display_name' => 'Actimed', 'spend_cents' => 384_608, 'open_tasks' => 1, 'tier_name' => null]);

    // A technician has no CRM; a customer's own people never see it, and a sibling's record does not exist for them.
    crmAs('bay@mekanikomore.ph');
    $this->getJson("/api/v1/crm/customers/{$this->actimed}")->assertForbidden();
    crmAs('donmiguel@mekanikomor.ph');
    $this->getJson("/api/v1/crm/customers/{$this->actimed}")->assertForbidden();
    $this->getJson('/api/v1/crm/customers/'.$this->world->id('fc-northwind'))->assertNotFound();
    $this->getJson('/api/v1/crm/customers')->assertForbidden();
});

it('keeps notes and tasks, and reminds the assignee once', function () {
    crmAs('advisor@mekanikomore.ph');
    $note = $this->postJson("/api/v1/crm/customers/{$this->actimed}/notes", ['body' => '  Asked for a loaner next time.  '])->assertCreated()->json('data');
    expect($note)->toMatchArray(['body' => 'Asked for a loaner next time.', 'pinned' => false, 'author_name' => 'Divina Lacson']);
    $this->putJson("/api/v1/crm/customers/{$this->actimed}/notes/{$note['id']}", ['pinned' => true])->assertOk()->assertJsonPath('data.pinned', true);
    $this->postJson("/api/v1/crm/customers/{$this->actimed}/notes", ['body' => 'x', 'vehicle_id' => $this->world->id('veh-101')])->assertStatus(422);
    $this->putJson('/api/v1/crm/customers/'.$this->world->id('fc-northwind')."/notes/{$note['id']}", ['pinned' => false])->assertNotFound();
    $this->deleteJson("/api/v1/crm/customers/{$this->actimed}/notes/{$note['id']}")->assertNoContent();

    $staff = collect($this->getJson('/api/v1/crm/options')->assertOk()->json('data.staff'))->pluck('id', 'name');
    $this->postJson('/api/v1/crm/tasks', ['title' => 'No subject'])->assertStatus(422)->assertJsonPath('error.details.fields.customer_account_id.0', 'A task is about a customer or a lead.');
    $task = $this->postJson('/api/v1/crm/tasks', [
        'title' => 'Call about the brake recall',
        'customer_account_id' => $this->actimed,
        'kind' => 'call',
        'assignee_id' => $staff['Divina Lacson'],
        'due_on' => '2026-10-07',
        'remind_at' => '2026-10-08T15:00:00+08:00',
    ])->assertCreated()->json('data');
    expect($task)->toMatchArray(['assignee_name' => 'Divina Lacson', 'customer_name' => 'Actimed', 'overdue' => true, 'reminder_due' => false, 'remind_at' => '2026-10-08T07:00:00Z']);
    $this->postJson('/api/v1/crm/tasks', ['title' => 'x', 'customer_account_id' => $this->actimed, 'assignee_id' => $this->world->id('donmiguel@mekanikomor.ph')])->assertStatus(422);

    $mine = $this->getJson('/api/v1/crm/tasks?mine=1')->assertOk()->json();
    expect(array_column($mine['data'], 'title'))->toBe(['Call about the brake recall'])
        ->and($mine['summary'])->toMatchArray(['open' => 3, 'overdue' => 1, 'reminders_due' => 0]);

    // Not before its time; then once.
    $this->artisan('crm:run tasks')->assertSuccessful();
    Mail::assertNothingSent();
    $this->travelTo(CarbonImmutable::parse('2026-10-08T15:01:00+08:00'));
    expect($this->getJson('/api/v1/crm/tasks?mine=1')->json('summary.reminders_due'))->toBe(1);
    $this->artisan('crm:run tasks')->assertSuccessful();
    $this->artisan('crm:run tasks')->assertSuccessful();
    Mail::assertSentCount(1);
    Mail::assertSent(CrmMessageMail::class, fn (CrmMessageMail $mail): bool => $mail->subjectLine === 'Reminder: Call about the brake recall' && $mail->hasTo('advisor@mekanikomore.ph') && str_contains($mail->body, 'About: Actimed'));

    // Moved to a new time, the reminder is due again; done, it is not.
    $this->putJson("/api/v1/crm/tasks/{$task['id']}", ['remind_at' => '2026-10-08T16:00:00+08:00'])->assertOk();
    $done = $this->postJson("/api/v1/crm/tasks/{$task['id']}/status", ['status' => 'done'])->assertOk()->json('data');
    expect($done)->toMatchArray(['status' => 'done', 'completed_by_name' => 'Divina Lacson', 'overdue' => false]);
    $this->travel(2)->hours();
    $this->artisan('crm:run tasks')->assertSuccessful();
    Mail::assertSentCount(1);
    expect($this->getJson('/api/v1/crm/tasks?status=done')->json('data.0.id'))->toBe($task['id']);
    $this->postJson("/api/v1/crm/tasks/{$task['id']}/status", ['status' => 'archived'])->assertStatus(422);
});

it('works a lead through the pipeline and wins it into a company account', function () {
    crmAs('advisor@mekanikomore.ph');
    $board = $this->getJson('/api/v1/crm/leads')->assertOk()->json('data');
    expect(array_column($board['stages'], 'value'))->toBe(['new', 'contacted', 'site_visit', 'quote_sent', 'negotiating', 'won', 'lost'])
        ->and(collect($board['stages'])->pluck('count', 'value')->all())->toBe(['new' => 1, 'contacted' => 0, 'site_visit' => 0, 'quote_sent' => 1, 'negotiating' => 1, 'won' => 0, 'lost' => 0])
        ->and($board['summary'])->toBe(['open' => 3, 'open_value_cents' => 8_500_000, 'won' => 0, 'won_value_cents' => 0, 'lost' => 0]);

    $lead = $this->postJson('/api/v1/crm/leads', ['company_name' => 'Calamba Courier Co.', 'contact_name' => 'Rey Santos', 'contact_email' => 'rey@calambacourier.example', 'value_cents' => 3_000_000, 'is_dealer' => true, 'next_step' => 'Visit their yard', 'next_step_on' => '2026-10-06'])->assertCreated()->json('data');
    expect($lead)->toMatchArray(['stage' => 'new', 'stage_label' => 'New', 'owner_name' => 'Divina Lacson', 'is_dealer' => true, 'next_step_overdue' => true, 'is_open' => true]);
    $id = $lead['id'];
    $move = fn (string $stage, array $extra = []) => $this->postJson("/api/v1/crm/leads/{$id}/stage", ['stage' => $stage] + $extra);

    $move('site_visit')->assertOk()->assertJsonPath('data.stage', 'site_visit');
    $move('contacted')->assertOk();   // a deal can go backwards
    $move('contacted')->assertStatus(409)->assertJsonPath('error.details.reason', 'invalid_transition');
    $move('lost')->assertStatus(422)->assertJsonPath('error.details.fields.lost_reason.0', 'Say why the lead was lost.');
    $move('lost', ['lost_reason' => 'Went with the dealer network'])->assertOk()->assertJsonPath('data.lost_reason', 'Went with the dealer network');
    $move('negotiating')->assertOk()->assertJsonPath('data.lost_reason', null);
    // Winning is a conversion, never a drag to the last column.
    $move('won')->assertStatus(409)->assertJsonPath('error.details.reason', 'convert_to_win');

    $this->postJson('/api/v1/crm/tasks', ['title' => 'Send the fleet programme deck', 'lead_id' => $id])->assertCreated();
    expect($this->getJson("/api/v1/crm/leads/{$id}")->assertOk()->json('data.tasks'))->toHaveCount(1);

    // Won: a company account with its terms and approval overrides, tagged as a dealer, with consent on record.
    $this->postJson("/api/v1/crm/leads/{$id}/convert", [], ['Idempotency-Key' => 'no-consent'])->assertStatus(422);
    $won = $this->postJson("/api/v1/crm/leads/{$id}/convert", [
        'consent_channel' => 'paper_form',
        'payment_terms_days' => 45,
        'credit_limit_cents' => 50_000_000,
        'registered_name' => 'Calamba Courier Company, Inc.',
        'tin' => '123-456-789',
        'approval_overrides' => ['auto_approve_under_cents' => 500_000, 'sla_hours' => 48],
        'service_reminders' => true,
    ], ['Idempotency-Key' => 'win'])->assertCreated()->json('data');
    expect($won)->toMatchArray(['stage' => 'won', 'is_open' => false])->and($won['customer_account']['display_name'])->toBe('Calamba Courier Co.');
    $accountId = $won['customer_account']['id'];

    crmAs('owner@mekanikomore.ph');
    $account = $this->getJson("/api/v1/customer-accounts/{$accountId}")->assertOk()->json('data');
    expect($account)->toMatchArray(['account_type' => 'company', 'payment_terms_days' => 45, 'credit_limit_cents' => 50_000_000, 'tags' => ['dealer'], 'source' => 'lead'])
        ->and($account['approval_threshold_overrides'])->toEqual(['auto_approve_under_cents' => 500_000, 'sla_hours' => 48]);

    $view = $this->getJson("/api/v1/crm/customers/{$accountId}")->assertOk()->json('data');
    expect($view['account']['is_dealer'])->toBeTrue()
        ->and(collect($view['consents'])->pluck('granted', 'purpose')->all())->toBe(['service_records' => true, 'service_reminders' => true, 'marketing' => false, 'vehicle_history_sharing' => null])
        // The lead's task came with it.
        ->and(array_column($view['tasks'], 'title'))->toBe(['Send the fleet programme deck']);
    // Dealers are a segment away.
    expect(array_column($this->postJson('/api/v1/crm/segments/preview', ['filters' => ['tags' => ['dealer']]])->json('data'), 'display_name'))->toBe(['Calamba Courier Co.']);

    // A won lead is closed: no edits, no second conversion.
    $this->putJson("/api/v1/crm/leads/{$id}", ['value_cents' => 1])->assertStatus(409);
    $this->postJson("/api/v1/crm/leads/{$id}/convert", ['consent_channel' => 'phone'], ['Idempotency-Key' => 'again'])->assertStatus(409);
    expect($this->getJson('/api/v1/crm/leads')->json('data.summary'))->toMatchArray(['open' => 3, 'won' => 1, 'won_value_cents' => 3_000_000]);

    // The technician cannot see the pipeline; the other organization's lead does not exist here.
    $this->getJson('/api/v1/crm/leads/'.$this->world->id('rival:crm:lead'))->assertNotFound();
    crmAs('bay@mekanikomore.ph');
    $this->getJson('/api/v1/crm/leads')->assertForbidden();
});
