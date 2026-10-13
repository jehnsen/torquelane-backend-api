<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Credit notes, refunds and creditable withholding (Phase 12). Seed (today
 * 2026-10-08), VAT 12% on top. Actimed: INV-2026-0001 ₱9,615.20 with
 * ₱3,846.08 paid; INV-2026-0003 ₱2,088.80 (₱1,865.00 + ₱223.80 VAT), unpaid.
 * Northwind: INV-2026-0002 ₱5,275.20 (₱4,710.00 + ₱565.20 VAT), unpaid.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->actimed = $this->world->id('fc-actimed');
    $this->northwind = $this->world->id('fc-northwind');
    $this->current = $this->world->id('invoice:actimed-current');
    $this->northwindInvoice = $this->world->id('invoice:northwind');
});

function cnAs(string $email = 'owner@mekanikomore.ph'): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function cnIssue(string $invoiceId, array $body): TestResponse
{
    return test()->postJson("/api/v1/invoices/{$invoiceId}/credit-notes", $body + ['reason' => 'Part returned'], ['Idempotency-Key' => 'cn-'.bin2hex(random_bytes(6))]);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function cnRefund(array $body): TestResponse
{
    return test()->postJson('/api/v1/refunds', $body + ['reason' => 'Customer asked for it back'], ['Idempotency-Key' => 'rf-'.bin2hex(random_bytes(6))]);
}

/** @return array<string, mixed> */
function cnInvoice(string $id): array
{
    return test()->getJson("/api/v1/invoices/{$id}")->assertOk()->json('data');
}

function cnBalance(string $code): int
{
    return collect(test()->getJson('/api/v1/ledger/accounts?as_of=2026-10-31')->assertOk()->json('data'))->firstWhere('code', $code)['balance_cents'];
}

/** @return array<string, bool> */
function cnChecks(): array
{
    $out = [];
    foreach (test()->getJson('/api/v1/ledger/periods/checklist?period=2026-10')->assertOk()->json('data.checklist') as $check) {
        $out[$check['key']] = $check['passed'];
    }

    return $out;
}

/** @return list<array{string, int, int}> */
function cnEntry(string $event, string $reference): array
{
    $entry = collect(test()->getJson('/api/v1/ledger/journal?q='.urlencode($reference).'&per_page=100')->assertOk()->json('data'))->firstWhere('event', $event);

    return array_map(fn (array $line): array => [$line['account_code'], $line['debit_cents'], $line['credit_cents']], $entry['lines'] ?? []);
}

it('credits an unpaid invoice: sales and VAT go back and the invoice owes less', function () {
    cnAs();
    $receivable = cnBalance('1100');

    // A ₱100.00 allowance before VAT: ₱112.00 comes off the invoice.
    $note = cnIssue($this->current, ['lines' => [['description' => 'Goodwill on labour', 'unit_price_cents' => 10_000]]])->assertCreated()->json('data');
    expect($note['number'])->toBe('CN-2026-0001')
        ->and($note['invoice_number'])->toBe('INV-2026-0003')
        ->and($note['issued_on'])->toBe('2026-10-08')
        ->and($note['totals'])->toMatchArray(['vatable_sales_cents' => 10_000, 'vat_amount_cents' => 1_200, 'total_cents' => 11_200])
        ->and($note['applied_cents'])->toBe(11_200)
        ->and($note['credit_cents'])->toBe(0)
        ->and($note['refundable_cents'])->toBe(0);

    $invoice = cnInvoice($this->current);
    expect($invoice['status'])->toBe('partially_paid')
        ->and($invoice['paid_cents'])->toBe(0)
        ->and($invoice['credited_cents'])->toBe(11_200)
        ->and($invoice['balance_cents'])->toBe(208_880 - 11_200)
        ->and($invoice['credit_notes'][0])->toMatchArray(['number' => 'CN-2026-0001', 'applied_cents' => 11_200, 'counts' => true])
        // An invoice with a credit note on it is not voided out from under it.
        ->and($this->postJson("/api/v1/invoices/{$this->current}/void", ['reason' => 'Wrong account'])->assertStatus(409)->json('error.message'))->toContain('credit notes');

    expect(cnEntry('credit_note_issued', 'CN-2026-0001'))->toBe([['4910', 10_000, 0], ['2200', 1_200, 0], ['1100', 0, 11_200]])
        ->and(cnBalance('1100'))->toBe($receivable - 11_200)
        ->and(cnChecks())->each->toBeTrue();

    // A line of the invoice, whole: what it billed comes back in its own tax class.
    $line = $invoice['lines'][0];
    $whole = cnIssue($this->current, ['lines' => [['invoice_line_id' => $line['id']]]])->assertCreated()->json('data');
    expect($whole['lines'][0])->toMatchArray(['invoice_line_id' => $line['id'], 'line_total_cents' => $line['line_total_cents'], 'tax_class' => $line['tax_class']]);
    // It is credited once: nothing of it is left.
    cnIssue($this->current, ['lines' => [['invoice_line_id' => $line['id']]]])->assertStatus(422);
    // And the notes on an invoice never exceed what it billed.
    cnIssue($this->current, ['lines' => [['description' => 'Too much', 'unit_price_cents' => 186_500]]])->assertStatus(422)->assertJsonPath('error.details.fields.lines.0', fn (string $m): bool => str_contains($m, 'more than it billed'));

    // The customer's statement and the revenue report show the credits.
    $statement = $this->getJson("/api/v1/customer-accounts/{$this->actimed}/statement?from=2026-10-01&to=2026-10-08")->assertOk()->json('data');
    expect(collect($statement['entries'])->where('kind', 'credit_note')->pluck('credit_cents')->all())->toBe([11_200, $whole['totals']['total_cents']]);
    $revenue = $this->getJson('/api/v1/receivables/revenue?from=2026-10-01&to=2026-10-08')->assertOk()->json('data');
    expect($revenue['accrual']['credited'])->toMatchArray(['credit_notes' => 2, 'total_cents' => 11_200 + $whole['totals']['total_cents']]);

    // Voiding a note puts what it took off back on the invoice.
    $this->postJson("/api/v1/credit-notes/{$whole['id']}/void", ['reason' => 'Keyed against the wrong line'])->assertOk()->assertJsonPath('data.status', 'void')->assertJsonPath('data.number', 'CN-2026-0002');
    expect(cnInvoice($this->current)['credited_cents'])->toBe(11_200)
        ->and(cnEntry('credit_note_voided', 'CN-2026-0002'))->not->toBe([])
        ->and(cnBalance('1100'))->toBe($receivable - 11_200)
        ->and(cnChecks())->each->toBeTrue();
    $this->postJson("/api/v1/credit-notes/{$whole['id']}/void", ['reason' => 'Again'])->assertStatus(409);
});

it('credits a paid invoice into the customer\'s credit, and a refund pays it out', function () {
    cnAs();
    $this->postJson('/api/v1/payments', ['customer_account_id' => $this->actimed, 'branch_id' => $this->world->id('mekanikomor-binan'), 'method' => 'cash', 'amount_cents' => 208_880, 'allocations' => [['invoice_id' => $this->current, 'amount_cents' => 208_880]]], ['Idempotency-Key' => 'pay-full'])->assertCreated();
    expect(cnInvoice($this->current)['status'])->toBe('paid');

    // The invoice owes nothing, so the whole note becomes credit the shop holds for the customer.
    $note = cnIssue($this->current, ['lines' => [['description' => 'Returned wiper blades', 'unit_price_cents' => 50_000]]])->assertCreated()->json('data');
    expect($note['applied_cents'])->toBe(0)
        ->and($note['credit_cents'])->toBe(56_000)
        ->and($note['refundable_cents'])->toBe(56_000)
        ->and($note['can_refund'])->toBeTrue()
        ->and(cnEntry('credit_note_issued', 'CN-2026-0001'))->toBe([['4910', 50_000, 0], ['2200', 6_000, 0], ['2300', 0, 56_000]])
        ->and(cnInvoice($this->current))->toMatchArray(['status' => 'paid', 'credited_cents' => 0, 'paid_cents' => 208_880])
        ->and($this->getJson("/api/v1/customer-accounts/{$this->actimed}/balance")->json('data.credit_cents'))->toBe(56_000)
        ->and(cnChecks())->each->toBeTrue();

    // Part of it back in cash; the rest by GCash, which needs its reference.
    $refund = cnRefund(['credit_note_id' => $note['id'], 'method' => 'cash', 'amount_cents' => 20_000])->assertCreated()->json('data');
    expect($refund)->toMatchArray(['number' => 'RF-2026-0001', 'source' => 'credit_note', 'credit_note_number' => 'CN-2026-0001', 'amount_cents' => 20_000, 'refunded_on' => '2026-10-08'])
        ->and(cnEntry('refund_paid', 'RF-2026-0001'))->toBe([['2300', 20_000, 0], ['1000', 0, 20_000]]);
    cnRefund(['credit_note_id' => $note['id'], 'method' => 'gcash'])->assertStatus(422)->assertJsonPath('error.details.fields.reference_no.0', 'A GCash refund needs its reference number.');
    cnRefund(['credit_note_id' => $note['id'], 'method' => 'cash', 'amount_cents' => 36_001])->assertStatus(422)->assertJsonPath('error.details.fields.amount_cents.0', fn (string $m): bool => str_contains($m, 'left to refund'));
    $rest = cnRefund(['credit_note_id' => $note['id'], 'method' => 'gcash', 'reference_no' => 'GC-REF-1'])->assertCreated()->json('data');
    expect($rest['amount_cents'])->toBe(36_000)
        ->and($this->getJson("/api/v1/credit-notes/{$note['id']}")->json('data'))->toMatchArray(['refunded_cents' => 56_000, 'refundable_cents' => 0, 'can_void' => false])
        ->and($this->getJson("/api/v1/customer-accounts/{$this->actimed}/balance")->json('data.credit_cents'))->toBe(0)
        ->and(cnBalance('2300'))->toBe(0)
        ->and(cnChecks())->each->toBeTrue();
    cnRefund(['credit_note_id' => $note['id'], 'method' => 'cash'])->assertStatus(409);

    // A refunded note is not voided until its refunds are; voiding a refund gives the credit back.
    $this->postJson("/api/v1/credit-notes/{$note['id']}/void", ['reason' => 'Wrong invoice'])->assertStatus(409);
    $this->postJson("/api/v1/refunds/{$rest['id']}/void", ['reason' => 'GCash reversed'])->assertOk()->assertJsonPath('data.status', 'void');
    expect($this->getJson("/api/v1/customer-accounts/{$this->actimed}/balance")->json('data.credit_cents'))->toBe(36_000)
        ->and(cnBalance('2300'))->toBe(36_000)
        ->and($this->getJson('/api/v1/refunds?status=posted')->json('data.*.number'))->toBe(['RF-2026-0001'])
        ->and(cnChecks())->each->toBeTrue();

    // The day's money: the refund comes off what was received.
    $day = collect($this->getJson('/api/v1/ledger/reports/daily-sales?from=2026-10-08&to=2026-10-08')->assertOk()->json('data.days'))->firstWhere('branch_id', $this->world->id('mekanikomor-binan'));
    expect($day['received_cents'])->toBe(208_880 - 20_000)->and($day['net_sales_cents'])->toBe(-50_000);
});

it('refunds an overpayment out of what the payment did not allocate', function () {
    cnAs();
    // Northwind owes ₱5,275.20 and pays ₱6,000.00: ₱724.80 is left as credit.
    $payment = $this->postJson('/api/v1/payments', ['customer_account_id' => $this->northwind, 'branch_id' => $this->world->id('mekanikomor-binan'), 'method' => 'cash', 'amount_cents' => 600_000], ['Idempotency-Key' => 'over'])->assertCreated()->json('data');
    expect($payment['unallocated_cents'])->toBe(72_480);

    $refund = cnRefund(['payment_id' => $payment['id'], 'method' => 'cash', 'amount_cents' => 50_000])->assertCreated()->json('data');
    expect($refund)->toMatchArray(['source' => 'payment', 'payment_number' => $payment['number'], 'amount_cents' => 50_000]);
    $after = $this->getJson("/api/v1/payments/{$payment['id']}")->json('data');
    expect($after['refunded_cents'])->toBe(50_000)
        ->and($after['unallocated_cents'])->toBe(22_480)
        ->and(cnBalance('2300'))->toBe(22_480)
        ->and(cnChecks())->each->toBeTrue();

    // No more than is left; a refunded payment is not voided until the refund is; one source, never two.
    cnRefund(['payment_id' => $payment['id'], 'method' => 'cash', 'amount_cents' => 22_481])->assertStatus(422);
    $this->postJson("/api/v1/payments/{$payment['id']}/void", ['reason' => 'Bounced'])->assertStatus(409)->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, 'refund'));
    cnRefund(['payment_id' => $payment['id'], 'credit_note_id' => $payment['id'], 'method' => 'cash'])->assertStatus(422);
    cnRefund(['method' => 'cash'])->assertStatus(422);

    // The database holds the same line: a refund past what the payment holds is refused.
    expect(fn () => DB::transaction(fn () => DB::statement(
        "insert into refunds (id, organization_id, branch_id, customer_account_id, number, method, amount_cents, refunded_on, payment_id, reason, refunded_by_name, created_at, updated_at)
         select '01zzzzzzzzzzzzzzzzzzzzzzzz', organization_id, branch_id, customer_account_id, 'RF-X', 'cash', 22481, '2026-10-08', id, 'x', 'x', now(), now() from payments where id = ?",
        [$payment['id']],
    )))->toThrow(QueryException::class);

    $this->postJson("/api/v1/refunds/{$refund['id']}/void", ['reason' => 'Paid to the wrong person'])->assertOk();
    $this->postJson("/api/v1/payments/{$payment['id']}/void", ['reason' => 'Bounced'])->assertOk();
    expect(cnBalance('2300'))->toBe(0)->and(cnChecks())->each->toBeTrue();
});

it('takes tax a customer withheld off the invoice and books the tax credit', function () {
    cnAs('cashier@mekanikomore.ph');
    // Northwind withholds 2% of ₱4,710.00 (the sale before VAT): ₱94.20.
    $certificate = $this->postJson("/api/v1/invoices/{$this->northwindInvoice}/withholding", ['atc_code' => 'wc160', 'rate_pct' => '2', 'certificate_no' => '2307-NW-0091'])->assertCreated()->json('data');
    expect($certificate)->toMatchArray([
        'atc_code' => 'WC160', 'rate_pct' => '2', 'tax_base_cents' => 471_000, 'amount_cents' => 9_420, 'received_on' => '2026-10-08',
        // The invoice was issued on 2026-08-19: the 2307 covers that quarter.
        'period_from' => '2026-07-01', 'period_to' => '2026-09-30', 'invoice_number' => 'INV-2026-0002', 'can_void' => false,
    ]);

    $invoice = cnInvoice($this->northwindInvoice);
    expect($invoice)->toMatchArray(['status' => 'partially_paid', 'withheld_cents' => 9_420, 'paid_cents' => 0, 'balance_cents' => 527_520 - 9_420])
        ->and($invoice['withholding'][0])->toMatchArray(['atc_code' => 'WC160', 'amount_cents' => 9_420, 'counts' => true]);

    // The rest in cash settles it: paid, and its jobs collected.
    $this->postJson('/api/v1/payments', ['customer_account_id' => $this->northwind, 'method' => 'cash', 'amount_cents' => 518_100], ['Idempotency-Key' => 'nw-rest'])->assertCreated()->assertJsonPath('data.unallocated_cents', 0);
    expect(cnInvoice($this->northwindInvoice))->toMatchArray(['status' => 'paid', 'balance_cents' => 0, 'settled_cents' => 527_520]);

    cnAs();
    expect(cnEntry('withholding_received', 'INV-2026-0002'))->toBe([['1310', 9_420, 0], ['1100', 0, 9_420]])
        ->and(cnBalance('1310'))->toBe(9_420)
        ->and(cnChecks())->each->toBeTrue();
    $statement = $this->getJson("/api/v1/customer-accounts/{$this->northwind}/statement?from=2026-10-01&to=2026-10-08")->assertOk()->json('data');
    expect(collect($statement['entries'])->firstWhere('kind', 'withholding')['credit_cents'])->toBe(9_420)
        ->and($statement['closing_balance_cents'])->toBe(0);

    // Never more than the invoice still owes, nor on more than it sold; and a void puts the amount back.
    $this->postJson("/api/v1/invoices/{$this->northwindInvoice}/withholding", ['atc_code' => 'WC160', 'rate_pct' => '2'])->assertStatus(409);
    $this->postJson("/api/v1/invoices/{$this->current}/withholding", ['atc_code' => 'WC160', 'rate_pct' => '2', 'tax_base_cents' => 186_501])->assertStatus(422);
    $this->postJson("/api/v1/invoices/{$this->current}/withholding", ['atc_code' => 'TWO', 'rate_pct' => '2'])->assertStatus(422);
    $this->postJson("/api/v1/withholding-certificates/{$certificate['id']}/void", ['reason' => 'Certificate was for another invoice'])->assertOk()->assertJsonPath('data.status', 'void');
    expect(cnInvoice($this->northwindInvoice))->toMatchArray(['status' => 'partially_paid', 'withheld_cents' => 0, 'balance_cents' => 9_420])
        ->and(cnBalance('1310'))->toBe(0)
        ->and(cnChecks())->each->toBeTrue();
});

it('lets the right people credit, refund and record withholding, and shows a customer only their own', function () {
    cnAs();
    $note = cnIssue($this->current, ['lines' => [['description' => 'Allowance', 'unit_price_cents' => 1_000]]])->assertCreated()->json('data');
    $other = cnIssue($this->northwindInvoice, ['lines' => [['description' => 'Allowance', 'unit_price_cents' => 1_000]]])->assertCreated()->json('data');

    // The advisor and the cashier record a 2307 (it settles like a payment) but cannot take sales back or void.
    cnAs('advisor@mekanikomore.ph');
    cnIssue($this->current, ['lines' => [['description' => 'Allowance', 'unit_price_cents' => 1_000]]])->assertForbidden();
    $this->postJson("/api/v1/credit-notes/{$note['id']}/void", ['reason' => 'No reason'])->assertForbidden();
    $certificate = $this->postJson("/api/v1/invoices/{$this->current}/withholding", ['atc_code' => 'WC160', 'rate_pct' => '2'])->assertCreated()->json('data');
    $this->postJson("/api/v1/withholding-certificates/{$certificate['id']}/void", ['reason' => 'No reason'])->assertForbidden();
    expect($this->getJson('/api/v1/credit-notes')->assertOk()->json('data.*.number'))->toBe(['CN-2026-0002', 'CN-2026-0001']);

    // Actimed's fleet manager reads their own account's; the shop's actions are not theirs; a sibling's is missing.
    cnAs('donmiguel@mekanikomor.ph');
    expect($this->getJson('/api/v1/credit-notes')->assertOk()->json('data.*.number'))->toBe(['CN-2026-0001'])
        ->and($this->getJson("/api/v1/credit-notes/{$note['id']}")->assertOk()->json('data'))->toMatchArray(['branch_id' => null, 'can_void' => false, 'can_refund' => false])
        ->and($this->getJson('/api/v1/withholding-certificates')->assertOk()->json('data.*.id'))->toBe([$certificate['id']])
        ->and($this->getJson('/api/v1/refunds')->assertOk()->json('data'))->toBe([]);
    $this->getJson("/api/v1/credit-notes/{$other['id']}")->assertNotFound();
    $this->getJson('/api/v1/credit-notes/'.$this->world->id('rival:credit-note'))->assertNotFound();
    $this->getJson('/api/v1/refunds/'.$this->world->id('rival:refund'))->assertNotFound();
    $this->getJson('/api/v1/withholding-certificates/'.$this->world->id('rival:withholding'))->assertNotFound();
    cnIssue($this->current, ['lines' => [['description' => 'Allowance', 'unit_price_cents' => 1_000]]])->assertForbidden();
    cnIssue($this->northwindInvoice, ['lines' => [['description' => 'Allowance', 'unit_price_cents' => 1_000]]])->assertNotFound();
    $this->postJson("/api/v1/invoices/{$this->current}/withholding", ['atc_code' => 'WC160', 'rate_pct' => '2'])->assertForbidden();

    // The detailing branch's manager never sees the repair branch's documents.
    cnAs('manager.samahuzai@mekanikomore.ph');
    $this->getJson("/api/v1/credit-notes/{$note['id']}")->assertNotFound();
    expect($this->getJson('/api/v1/credit-notes')->assertOk()->json('data'))->toBe([]);
});

it('keeps an issued credit note still in the database, and the invoice\'s figures true at commit', function () {
    cnAs();
    $note = cnIssue($this->current, ['lines' => [['description' => 'Allowance', 'unit_price_cents' => 1_000]]])->assertCreated()->json('data');

    $refused = function (string $sql): string {
        try {
            DB::transaction(fn () => DB::statement($sql));
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        return 'accepted';
    };
    expect($refused("update credit_notes set total_cents = total_cents + 1, applied_cents = applied_cents + 1 where id = '{$note['id']}'"))->toBe('23001')
        ->and($refused("delete from credit_notes where id = '{$note['id']}'"))->toBe('23001')
        ->and($refused("update credit_note_lines set line_total_cents = 1 where credit_note_id = '{$note['id']}'"))->toBe('23001');

    // credited_cents is what the standing notes applied: a figure that disagrees fails the commit.
    expect(fn () => DB::transaction(function (): void {
        DB::statement("update invoices set credited_cents = credited_cents + 100 where id = '{$this->current}'");
        DB::statement('set constraints all immediate');
    }))->toThrow(QueryException::class);
});
