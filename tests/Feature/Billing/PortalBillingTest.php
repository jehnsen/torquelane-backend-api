<?php

declare(strict_types=1);

use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The customer's side of billing: a portal user with `billing:view` sees
 * their own account's ISSUED invoices, its payments, balance and statement,
 * and prints them; never a draft, never a sibling account's, never the shop's
 * billing queue, aging or revenue, and moves no money.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
});

function portalAs(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

it('shows a fleet manager their own issued invoices and payments only', function () {
    // A draft for Actimed exists too: the shop's working paper, not shown.
    portalAs('advisor@mekanikomore.ph');
    $job = asSystem(fn () => WorkOrder::query()->where('customer_account_id', $this->world->id('fc-actimed'))->where('status', 'closed')->whereNull('collected_at')->whereDoesntHave('invoiceLinks')->value('id'));
    $draft = $this->postJson('/api/v1/invoices', ['work_order_ids' => [$job]])->assertCreated()->json('data.id');

    portalAs('donmiguel@mekanikomor.ph');
    $list = $this->getJson('/api/v1/invoices')->assertOk();
    expect($list->json('data.*.number'))->toBe(['INV-2026-0003', 'INV-2026-0001'])
        ->and($list->json('data.*.branch_id'))->each->toBeNull()
        ->and($list->json('data.0.can_record_payment'))->toBeFalse()
        ->and($list->json('data.0.can_void'))->toBeFalse();

    $this->getJson('/api/v1/invoices/'.$this->world->id('invoice:actimed-overdue'))->assertOk()->assertJsonPath('data.payments.0.number', 'PAY-2026-0001');
    $this->get('/api/v1/invoices/'.$this->world->id('invoice:actimed-overdue').'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    foreach ([$draft, $this->world->id('invoice:northwind'), $this->world->id('invoice:sagrada-draft'), $this->world->id('rival:invoice')] as $hidden) {
        $this->getJson("/api/v1/invoices/{$hidden}")->assertNotFound();
        $this->get("/api/v1/invoices/{$hidden}/pdf")->assertNotFound();
    }

    expect($this->getJson('/api/v1/payments')->assertOk()->json('data.*.number'))->toBe(['PAY-2026-0001']);
    $this->getJson('/api/v1/payments/'.$this->world->id('rival:payment'))->assertNotFound();

    // Their own account's balance and statement; a sibling's is missing, not forbidden.
    $this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed').'/balance')
        ->assertOk()
        ->assertJsonPath('data.outstanding_cents', 785792)
        ->assertJsonPath('data.overdue_cents', 576912)
        ->assertJsonPath('data.uninvoiced_jobs', null);
    $this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed').'/statement')->assertOk();
    $this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-northwind').'/balance')->assertNotFound();
    $this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-northwind').'/statement')->assertNotFound();
});

it('keeps the shop\'s billing to the shop: no queue, aging, revenue, invoicing or payments from the portal', function () {
    portalAs('donmiguel@mekanikomor.ph');

    $this->getJson('/api/v1/billing/queue')->assertForbidden();
    $this->getJson('/api/v1/receivables/aging')->assertForbidden();
    $this->getJson('/api/v1/receivables/revenue')->assertForbidden();
    $this->postJson('/api/v1/invoices', ['customer_account_id' => $this->world->id('fc-actimed'), 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price_cents' => 1]]])->assertForbidden();
    $this->postJson('/api/v1/payments', ['customer_account_id' => $this->world->id('fc-actimed'), 'method' => 'cash', 'amount_cents' => 100], ['Idempotency-Key' => 'portal-pay'])->assertForbidden();
    $this->postJson('/api/v1/invoices/'.$this->world->id('invoice:actimed-current').'/void', ['reason' => 'Disputed'])->assertForbidden();
    $this->postJson('/api/v1/payments/'.$this->world->id('payment:actimed-partial').'/void', ['reason' => 'Disputed'])->assertForbidden();
});

it('gives billing to the roles that hold billing:view, and each account its own', function () {
    // Operations staff of the customer have no billing:view.
    portalAs('ops@mekanikomore.ph');
    $this->getJson('/api/v1/invoices')->assertForbidden();
    $this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-actimed').'/balance')->assertForbidden();

    portalAs('viewer@mekanikomore.ph');
    expect($this->getJson('/api/v1/invoices')->assertOk()->json('data.*.number'))->toBe(['INV-2026-0003', 'INV-2026-0001']);

    portalAs('fleet@northwind.ph');
    expect($this->getJson('/api/v1/invoices')->assertOk()->json('data.*.number'))->toBe(['INV-2026-0002'])
        ->and($this->getJson('/api/v1/payments')->json('data'))->toBe([]);

    // Staff pinned to the detailing branch do not see the repair branch's receivables.
    portalAs('manager.samahuzai@mekanikomore.ph');
    $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/invoices/'.$this->world->id('invoice:actimed-overdue'))->assertNotFound();
    $this->getJson('/api/v1/payments/'.$this->world->id('payment:actimed-partial'))->assertNotFound();

    // A technician bills nothing and sees no invoices.
    portalAs('bay@mekanikomore.ph');
    $this->getJson('/api/v1/invoices')->assertForbidden();
});
