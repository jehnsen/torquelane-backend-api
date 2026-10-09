<?php

declare(strict_types=1);

use App\Events\PaymentReceived;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Payments and allocations (Phase 7). Seed (today 2026-10-08), Actimed:
 * INV-2026-0001 ₱9,615.20, ₱3,846.08 paid by PAY-2026-0001 (due 2026-08-24,
 * 45 days late); INV-2026-0003 ₱2,088.80 (due 2026-10-18). Northwind:
 * INV-2026-0002 ₱5,275.20 (due 2026-10-03).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->actimed = $this->world->id('fc-actimed');
    $this->overdue = $this->world->id('invoice:actimed-overdue');
    $this->current = $this->world->id('invoice:actimed-current');
});

function payAs(string $email): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function recordPayment(array $body, ?string $key = null): TestResponse
{
    return test()->postJson('/api/v1/payments', $body + ['customer_account_id' => test()->actimed], ['Idempotency-Key' => $key ?? 'pay-'.bin2hex(random_bytes(6))]);
}

/** @return array{status: string, paid_cents: int, balance_cents: int, total: int} */
function invoiceState(string $id): array
{
    $invoice = test()->getJson("/api/v1/invoices/{$id}")->assertOk()->json('data');

    return ['status' => $invoice['status'], 'paid_cents' => $invoice['paid_cents'], 'balance_cents' => $invoice['balance_cents'], 'total' => $invoice['totals']['total_due_cents']];
}

it('spreads one payment across invoices; what is left is credit, applied later', function () {
    payAs('cashier@mekanikomore.ph');
    $overdue = invoiceState($this->overdue);
    $current = invoiceState($this->current);
    expect($overdue)->toBe(['status' => 'partially_paid', 'paid_cents' => 384608, 'balance_cents' => 576912, 'total' => 961520]);

    $payment = recordPayment([
        'method' => 'check',
        'reference_no' => 'MBTC 004512',
        'amount_cents' => 1_000_000,
        'allocations' => [
            ['invoice_id' => $this->current, 'amount_cents' => $current['balance_cents']],
            ['invoice_id' => $this->overdue, 'amount_cents' => $overdue['balance_cents']],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.allocated_cents', 576912 + 208880)
        ->assertJsonPath('data.unallocated_cents', 1_000_000 - 576912 - 208880)
        ->assertJsonPath('data.can_allocate', true)
        ->assertJsonPath('data.can_void', false)
        ->json('data');

    expect(invoiceState($this->overdue)['status'])->toBe('paid')
        ->and(invoiceState($this->current)['status'])->toBe('paid');
    // A paid invoice settles its jobs: collected_at is stamped, the stage is completed.
    $jobs = asSystem(fn () => WorkOrder::query()->whereIn('id', Invoice::query()->findOrFail($this->overdue)->workOrderLinks()->pluck('work_order_id'))->get());
    expect($jobs)->toHaveCount(3)
        ->and($jobs->every(fn (WorkOrder $o): bool => $o->collected_at !== null && $o->collected_by === $this->world->id('cashier@mekanikomore.ph')))->toBeTrue();
    $this->getJson('/api/v1/work-orders/'.$jobs->first()->id)->assertJsonPath('data.lifecycle_stage', 'completed');

    $this->getJson("/api/v1/customer-accounts/{$this->actimed}/balance")
        ->assertOk()
        ->assertJsonPath('data.outstanding_cents', 0)
        ->assertJsonPath('data.credit_cents', 214208)
        ->assertJsonPath('data.net_balance_cents', -214208);

    // A new invoice takes the credit: oldest due first when nothing is said.
    payAs('advisor@mekanikomore.ph');
    $job = asSystem(fn () => WorkOrder::query()->where('customer_account_id', $this->actimed)->where('status', 'closed')->whereNull('collected_at')->whereDoesntHave('invoiceLinks')->orderBy('completed_on')->value('id'));
    $invoice = $this->postJson('/api/v1/invoices', ['work_order_ids' => [$job]])->assertCreated()->json('data.id');
    $total = $this->postJson("/api/v1/invoices/{$invoice}/issue", [], ['Idempotency-Key' => 'issue-credit'])->assertOk()->json('data.totals.total_due_cents');

    payAs('cashier@mekanikomore.ph');
    $this->postJson("/api/v1/payments/{$payment['id']}/allocations")
        ->assertOk()
        ->assertJsonPath('data.allocations.2.invoice_id', $invoice)
        ->assertJsonPath('data.allocations.2.amount_cents', min($total, 214208))
        ->assertJsonPath('data.unallocated_cents', max(0, 214208 - $total));
    expect(invoiceState($invoice)['paid_cents'])->toBe(min($total, 214208));
});

it('goes to the oldest due first when the payment says nothing', function () {
    payAs('cashier@mekanikomore.ph');

    $payment = recordPayment(['method' => 'cash', 'amount_cents' => 600_000])->assertCreated()->json('data');

    // INV-0001 (due 2026-08-24) takes its ₱5,769.12 first; INV-0003 (due 2026-10-18) the rest.
    expect(array_map(fn (array $a): array => [$a['invoice_id'], $a['amount_cents']], $payment['allocations']))->toBe([
        [$this->overdue, 576912],
        [$this->current, 600_000 - 576912],
    ])->and($payment['unallocated_cents'])->toBe(0)
        ->and(invoiceState($this->overdue)['status'])->toBe('paid')
        ->and(invoiceState($this->current)['status'])->toBe('partially_paid');
});

it('replays a retried payment, never takes it twice, and demands the key', function () {
    payAs('cashier@mekanikomore.ph');
    Event::fake([PaymentReceived::class]);
    $body = ['method' => 'maya', 'reference_no' => 'MAYA-1', 'amount_cents' => 50_000];

    $first = recordPayment($body, 'counter-retry')->assertCreated()->json('data');
    recordPayment($body, 'counter-retry')
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first['id'])
        ->assertJsonPath('data.number', 'PAY-2026-0002');
    recordPayment(['amount_cents' => 50_001] + $body, 'counter-retry')->assertStatus(409)->assertJsonPath('error.code', 'conflict');

    expect(asSystem(fn () => Payment::query()->count()))->toBe(3)  // the seed's, the rival's, and this one
        ->and(invoiceState($this->overdue)['paid_cents'])->toBe(384608 + 50_000);
    Event::assertDispatchedTimes(PaymentReceived::class, 1);

    $this->postJson('/api/v1/payments', $body + ['customer_account_id' => $this->actimed])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields', fn (array $f) => isset($f['idempotency_key']));
});

it('refuses a payment that does not add up or does not say how it came in', function () {
    payAs('cashier@mekanikomore.ph');

    recordPayment(['method' => 'gcash', 'amount_cents' => 10_000])->assertUnprocessable()->assertJsonPath('error.details.fields.reference_no.0', 'A GCash payment needs its reference number.');
    recordPayment(['method' => 'cash', 'amount_cents' => 0])->assertUnprocessable();
    recordPayment(['method' => 'cash', 'amount_cents' => 10_000, 'received_on' => '2026-10-09'])->assertUnprocessable();
    recordPayment(['method' => 'cash', 'amount_cents' => 1_000_000, 'allocations' => [['invoice_id' => $this->current, 'amount_cents' => 208881]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields', fn (array $f) => isset($f['allocations.0']));
    recordPayment(['method' => 'cash', 'amount_cents' => 1_000, 'allocations' => [['invoice_id' => $this->current, 'amount_cents' => 2_000]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.allocations.0', 'The allocations add up to ₱20.00; only ₱10.00 is available.');
    // Another account's invoice is not open for this one.
    recordPayment(['method' => 'cash', 'amount_cents' => 1_000, 'allocations' => [['invoice_id' => $this->world->id('invoice:northwind'), 'amount_cents' => 1_000]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields', fn (array $f): bool => ($f['allocations.0'][0] ?? null) === 'That invoice is not open for this account.');

    expect(asSystem(fn () => Payment::query()->where('customer_account_id', $this->actimed)->count()))->toBe(1);
});

it('voids a payment: it keeps its number, and every invoice it paid is owed again', function () {
    payAs('cashier@mekanikomore.ph');
    $payment = recordPayment(['method' => 'bank_transfer', 'reference_no' => 'BPI-9', 'amount_cents' => 576912, 'allocations' => [['invoice_id' => $this->overdue, 'amount_cents' => 576912]]])->json('data');
    expect(invoiceState($this->overdue)['status'])->toBe('paid');
    $job = asSystem(fn () => Invoice::query()->findOrFail($this->overdue)->workOrderLinks()->value('work_order_id'));

    $this->postJson("/api/v1/payments/{$payment['id']}/void", ['reason' => 'Bounced'])->assertForbidden();

    payAs('owner@mekanikomore.ph');
    $this->postJson("/api/v1/payments/{$payment['id']}/void", ['reason' => 'Transfer reversed by the bank.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.number', 'PAY-2026-0002')
        ->assertJsonPath('data.unallocated_cents', 0)
        ->assertJsonPath('data.can_void', false);
    $this->postJson("/api/v1/payments/{$payment['id']}/void", ['reason' => 'Again'])->assertStatus(409);

    expect(invoiceState($this->overdue))->toBe(['status' => 'partially_paid', 'paid_cents' => 384608, 'balance_cents' => 576912, 'total' => 961520]);
    $this->getJson("/api/v1/invoices/{$this->overdue}")->assertJsonPath('data.payments.1.counts', false);
    // Its jobs are no longer settled.
    $this->getJson("/api/v1/work-orders/{$job}")->assertJsonPath('data.collected_at', null)->assertJsonPath('data.lifecycle_stage', 'invoiced');

    // An invoice with payments standing against it cannot be voided until they are.
    $this->postJson("/api/v1/invoices/{$this->overdue}/void", ['reason' => 'Wrong'])->assertStatus(409)->assertJsonPath('error.message', 'Invoice INV-2026-0001 has payments against it; void those payments first.');

    // At commit, every invoice's paid figure matches its standing payments.
    DB::statement("select set_config('torquelane.stock_ledger', '', true)");
    DB::statement('set constraints all immediate');
    DB::statement('set constraints all deferred');
});

it('holds the money in the database: posted payments never change, allocations never overrun, paid follows the allocations', function () {
    $payment = $this->world->id('payment:actimed-partial');
    $fails = function (string $sql, array $bindings = []): string {
        try {
            DB::transaction(function () use ($sql, $bindings): void {
                DB::statement($sql, $bindings);
                DB::statement('set constraints all immediate');
            });
        } catch (QueryException $e) {
            return (string) $e->getCode();
        } finally {
            DB::statement('set constraints all deferred');
        }

        return 'accepted';
    };

    $organization = $this->world->id('prov-mekanikomore');
    expect($fails('update payments set amount_cents = amount_cents + 1 where id = ?', [$payment]))->toBe('23001')
        ->and($fails('delete from payments where id = ?', [$payment]))->toBe('23001')
        ->and($fails('update payment_allocations set amount_cents = 1 where payment_id = ?', [$payment]))->toBe('23001')
        // More than the payment holds.
        ->and($fails("insert into payment_allocations (id, organization_id, payment_id, invoice_id, customer_account_id, amount_cents, allocated_on, allocated_at, allocated_by_name) values ('01k0000000000000000000000a', ?, ?, ?, ?, 1, '2026-10-08', now(), 'x')", [$organization, $payment, $this->current, $this->actimed]))->toBe('23514')
        // An invoice whose paid figure disagrees with its payments, caught at commit.
        ->and($fails('update invoices set paid_cents = paid_cents + 1 where id = ?', [$this->overdue]))->toBe('23514');
});

it('ages receivables as of a date, per account, with credit', function () {
    payAs('advisor@mekanikomore.ph');

    $today = $this->getJson('/api/v1/receivables/aging')->assertOk()->json('data');
    expect($today['as_of'])->toBe('2026-10-08')
        ->and($today['accounts'])->toBe([
            ['customer_account_id' => $this->actimed, 'customer_name' => 'Actimed', 'current' => 208880, 'days_1_30' => 0, 'days_31_60' => 576912, 'days_61_90' => 0, 'over_90' => 0, 'total' => 785792, 'credit_cents' => 0],
            ['customer_account_id' => $this->world->id('fc-northwind'), 'customer_name' => 'Northwind Logistics', 'current' => 0, 'days_1_30' => 527520, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 0, 'total' => 527520, 'credit_cents' => 0],
        ])
        ->and($today['totals']['total'])->toBe(785792 + 527520);

    // As of 2026-09-01: only INV-0001 had been issued; the part payment came on 2026-09-08.
    $earlier = $this->getJson('/api/v1/receivables/aging?as_of=2026-09-01')->assertOk()->json('data');
    expect($earlier['accounts'])->toHaveCount(2)
        ->and($earlier['accounts'][0]['customer_name'])->toBe('Actimed')
        ->and($earlier['accounts'][0]['total'])->toBe(961520)
        ->and($earlier['accounts'][0]['days_1_30'])->toBe(961520);
});

it('states an account: brought forward, every movement, closing', function () {
    payAs('advisor@mekanikomore.ph');

    $statement = $this->getJson("/api/v1/customer-accounts/{$this->actimed}/statement?from=2026-09-01&to=2026-10-08")->assertOk()->json('data');

    expect($statement['opening_balance_cents'])->toBe(961520)
        ->and(array_map(fn (array $e): array => [$e['date'], $e['kind'], $e['reference'], $e['charge_cents'], $e['credit_cents'], $e['balance_cents']], $statement['entries']))->toBe([
            ['2026-09-08', 'payment', 'PAY-2026-0001', 0, 384608, 576912],
            ['2026-09-18', 'invoice', 'INV-2026-0003', 208880, 0, 785792],
        ])
        ->and($statement['closing_balance_cents'])->toBe(785792);

    $this->getJson("/api/v1/customer-accounts/{$this->actimed}/statement?from=2026-10-08&to=2026-09-01")->assertUnprocessable();
});

it('reports revenue two ways: invoiced (accrual) and received (cash)', function () {
    payAs('owner@mekanikomore.ph');

    $revenue = $this->getJson('/api/v1/receivables/revenue?from=2026-07-01&to=2026-10-08')->assertOk()->json('data');
    expect($revenue['accrual'])->toBe(['invoices' => 3, 'net_sales_cents' => 858500 + 471000 + 186500, 'vat_cents' => 103020 + 56520 + 22380, 'total_cents' => 961520 + 527520 + 208880])
        ->and($revenue['cash'])->toBe(['payments' => 1, 'received_cents' => 384608, 'by_method' => [['method' => 'bank_transfer', 'received_cents' => 384608]]]);
});
