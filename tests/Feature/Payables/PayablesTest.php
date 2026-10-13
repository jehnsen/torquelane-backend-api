<?php

declare(strict_types=1);

use App\Models\VendorBill;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Accounts payable (Phase 12) over the demo seed (today 2026-10-08). The
 * stock room's SPO-2026-0001 was part-received as GR-2026-0001, so that
 * receipt's value is what GR/IR holds, waiting for the vendor's bill.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->repair = $this->world->id('mekanikomor-binan');
});

function apAs(string $email = 'owner@mekanikomore.ph'): void
{
    Sanctum::actingAs(test()->world->user($email));
}

function apBalance(string $code, string $asOf = '2026-10-31'): int
{
    return collect(test()->getJson("/api/v1/ledger/accounts?as_of={$asOf}")->assertOk()->json('data'))->firstWhere('code', $code)['balance_cents'];
}

function apAccount(string $code): string
{
    return collect(test()->getJson('/api/v1/ledger/accounts')->assertOk()->json('data'))->firstWhere('code', $code)['id'];
}

/** @return array<string, bool> */
function apChecks(string $period = '2026-10'): array
{
    $out = [];
    foreach (test()->getJson("/api/v1/ledger/periods/checklist?period={$period}")->assertOk()->json('data.checklist') as $check) {
        $out[$check['key']] = $check['passed'];
    }

    return $out;
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function apBill(array $body, ?string $key = null): TestResponse
{
    return test()->postJson('/api/v1/vendor-bills', $body, ['Idempotency-Key' => $key ?? 'bill-'.bin2hex(random_bytes(6))]);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function apPay(array $body, ?string $key = null): TestResponse
{
    return test()->postJson('/api/v1/vendor-payments', $body, ['Idempotency-Key' => $key ?? 'pv-'.bin2hex(random_bytes(6))]);
}

/** A vendor that bills expenses: VAT-registered, 30-day terms, rent withheld at 5%. */
function apLandlord(array $attributes = []): string
{
    return test()->postJson('/api/v1/vendors', $attributes + [
        'name' => 'Biñan Commercial Leasing',
        'registered_name' => 'Biñan Commercial Leasing Corp.',
        'tin' => '004-512-778',
        'address' => 'National Highway, Biñan, Laguna',
        'payment_terms_days' => 30,
        'default_atc_code' => 'WC100',
        'default_withholding_rate_pct' => '5',
    ])->assertCreated()->json('data.id');
}

/** @return list<array{string, int, int}> account code, debit, credit of the first entry of an event for a reference */
function apEntry(string $event, string $reference): array
{
    $entry = collect(test()->getJson('/api/v1/ledger/journal?q='.urlencode($reference).'&per_page=100')->assertOk()->json('data'))->firstWhere('event', $event);

    return array_map(fn (array $line): array => [$line['account_code'], $line['debit_cents'], $line['credit_cents']], $entry['lines'] ?? []);
}

it('bills goods received: GR/IR clears at the receipt value, the price difference is a variance, and payment settles it', function () {
    apAs();
    $unbilled = $this->getJson('/api/v1/vendor-bills/unbilled-receipts')->assertOk()->json('data');
    $grir = apBalance('2100');
    expect($unbilled)->not->toBeEmpty()
        ->and(array_sum(array_column($unbilled, 'received_value_cents')))->toBe($grir)
        ->and($unbilled[0]['receipt_reference'])->toBe('GR-2026-0001');

    $vendorId = $unbilled[0]['vendor_id'];
    // The vendor charged ₱1.00 a unit more than the receipt recorded on the first line.
    $lines = [];
    foreach ($unbilled as $index => $line) {
        $lines[] = ['kind' => 'goods', 'goods_receipt_line_id' => $line['goods_receipt_line_id']] + ($index === 0 ? ['unit_cost_cents' => $line['unit_cost_cents'] + 100] : []);
    }
    $firstQuantity = (float) $unbilled[0]['quantity'];
    $variance = (int) round($firstQuantity * 100);
    $goods = array_sum(array_column($unbilled, 'line_total_cents')) + $variance;
    $delivery = 50_000;

    $bill = apBill([
        'vendor_id' => $vendorId,
        'branch_id' => $this->repair,
        'vendor_invoice_no' => 'SI-88231',
        'withholding' => ['atc_code' => 'wc158', 'rate_pct' => '1'],
        'lines' => [...$lines, ['kind' => 'expense', 'account_id' => apAccount('6190'), 'description' => 'Delivery charge', 'unit_cost_cents' => $delivery]],
    ], 'bill-1')->assertCreated()->json('data');

    $net = $goods + $delivery;
    $vat = (int) round($net * 0.12);
    $withheld = (int) round($net * 0.01);
    expect($bill['number'])->toBe('VB-2026-0001')
        ->and($bill['status'])->toBe('posted')
        ->and($bill['bill_date'])->toBe('2026-10-08')
        ->and($bill['totals'])->toMatchArray(['vatable_purchases_cents' => $net, 'input_vat_cents' => $vat, 'total_cents' => $net + $vat])
        ->and($bill['withholding'])->toBe(['atc_code' => 'WC158', 'rate_pct' => '1', 'tax_base_cents' => $net, 'amount_cents' => $withheld])
        ->and($bill['payable_cents'])->toBe($net + $vat - $withheld)
        ->and($bill['balance_cents'])->toBe($net + $vat - $withheld)
        ->and($bill['lines'][0]['price_variance_cents'])->toBe($variance)
        ->and(array_sum(array_column($bill['lines'], 'net_cents')))->toBe($net);

    // A retried click replays the bill; it does not record a second one.
    expect(apBill(['vendor_id' => $vendorId, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'SI-88231', 'withholding' => ['atc_code' => 'wc158', 'rate_pct' => '1'], 'lines' => [...$lines, ['kind' => 'expense', 'account_id' => apAccount('6190'), 'description' => 'Delivery charge', 'unit_cost_cents' => $delivery]]], 'bill-1')->json('data.id'))->toBe($bill['id']);

    // The books: GR/IR is empty, the difference sits in Purchase Price Variance, the rest is owed.
    expect(apBalance('2100'))->toBe(0)
        ->and(apBalance('5110'))->toBe($variance)
        ->and(apBalance('1300'))->toBe($vat)
        ->and(apBalance('2210'))->toBe($withheld)
        ->and(apBalance('2000'))->toBe($bill['payable_cents'])
        ->and(apChecks())->each->toBeTrue();
    expect($this->getJson('/api/v1/vendor-bills/unbilled-receipts')->json('data'))->toBe([]);

    // Billed once; the vendor's invoice is recorded once; a billed receipt is not voided from under its bill.
    apBill(['vendor_id' => $vendorId, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'SI-88232', 'lines' => [$lines[0]]])->assertStatus(422)->assertJsonPath('error.details.fields', fn (array $fields): bool => str_contains(json_encode($fields), 'VB-2026-0001'));
    apBill(['vendor_id' => $vendorId, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'si-88231', 'lines' => [['kind' => 'expense', 'account_id' => apAccount('6190'), 'description' => 'Again', 'unit_cost_cents' => 100]]])->assertStatus(422)->assertJsonPath('error.details.fields.vendor_invoice_no.0', fn (string $m): bool => str_contains($m, 'already recorded'));
    $this->postJson('/api/v1/goods-receipts/'.$this->world->id('goods-receipt:top-up').'/void', ['reason' => 'Wrong delivery'])->assertStatus(409)->assertJsonPath('error.message', fn (string $m): bool => str_contains($m, 'VB-2026-0001'));

    // Part payment by bank transfer.
    $payment = apPay(['vendor_id' => $vendorId, 'branch_id' => $this->repair, 'method' => 'bank_transfer', 'reference_no' => 'BDO-55120', 'amount_cents' => 100_000], 'pv-1')->assertCreated()->json('data');
    expect($payment['number'])->toBe('PV-2026-0001')
        ->and($payment['allocations'])->toHaveCount(1)
        ->and($payment['allocations'][0]['bill_number'])->toBe('VB-2026-0001')
        ->and(apEntry('vendor_payment_made', 'PV-2026-0001'))->toBe([['2000', 100_000, 0], ['1010', 0, 100_000]]);
    $this->getJson("/api/v1/vendor-bills/{$bill['id']}")->assertJsonPath('data.status', 'partially_paid')->assertJsonPath('data.paid_cents', 100_000)->assertJsonPath('data.can_void', false);
    expect(apBalance('2000'))->toBe($bill['payable_cents'] - 100_000)->and(apChecks())->each->toBeTrue();

    // A payment settles bills and nothing more.
    apPay(['vendor_id' => $vendorId, 'branch_id' => $this->repair, 'method' => 'cash', 'amount_cents' => $bill['payable_cents']])->assertStatus(422);
    // A bill with a payment against it is not voided; void the payment, then the bill.
    $this->postJson("/api/v1/vendor-bills/{$bill['id']}/void", ['reason' => 'Wrong vendor'])->assertStatus(409);
    $this->postJson("/api/v1/vendor-payments/{$payment['id']}/void", ['reason' => 'Transfer bounced'])->assertOk()->assertJsonPath('data.status', 'void');
    $this->postJson("/api/v1/vendor-bills/{$bill['id']}/void", ['reason' => 'Wrong vendor'])->assertOk()->assertJsonPath('data.status', 'void')->assertJsonPath('data.number', 'VB-2026-0001');

    // Everything is back where it was: the receipt waits in GR/IR again and nothing is owed.
    expect(apBalance('2100'))->toBe($grir)
        ->and(apBalance('2000'))->toBe(0)
        ->and(apBalance('1300'))->toBe(0)
        ->and(apBalance('5110'))->toBe(0)
        ->and(apBalance('1010'))->toBe(384_608)
        ->and(apChecks())->each->toBeTrue();
    expect($this->getJson('/api/v1/vendor-bills/unbilled-receipts')->json('data'))->toHaveCount(count($unbilled));
});

it('records an expense paid on the spot: VAT extracted, tax withheld, cash out, in one transaction', function () {
    apAs();
    $landlord = apLandlord();

    // ₱1,120.00 VAT-inclusive rent: ₱1,000.00 + ₱120.00 VAT; 5% of ₱1,000.00 withheld (the vendor's usual code).
    $bill = apBill([
        'vendor_id' => $landlord,
        'branch_id' => $this->repair,
        'vendor_invoice_no' => 'RENT-2026-10',
        'prices_include_vat' => true,
        'lines' => [['kind' => 'expense', 'account_id' => apAccount('6100'), 'description' => 'October rent', 'unit_cost_cents' => 112_000]],
        'payment' => ['method' => 'cash'],
    ])->assertCreated()->json('data');

    expect($bill['status'])->toBe('paid')
        ->and($bill['due_date'])->toBe('2026-11-07')
        ->and($bill['vendor'])->toBe(['name' => 'Biñan Commercial Leasing Corp.', 'tin' => '004-512-778', 'address' => 'National Highway, Biñan, Laguna', 'vat_registered' => true])
        ->and($bill['totals'])->toMatchArray(['vatable_purchases_cents' => 100_000, 'input_vat_cents' => 12_000, 'total_cents' => 112_000])
        ->and($bill['withholding'])->toBe(['atc_code' => 'WC100', 'rate_pct' => '5', 'tax_base_cents' => 100_000, 'amount_cents' => 5_000])
        ->and($bill['payable_cents'])->toBe(107_000)
        ->and($bill['balance_cents'])->toBe(0)
        ->and($bill['payments'][0]['number'])->toBe('PV-2026-0001');

    expect(apEntry('vendor_bill_posted', 'VB-2026-0001'))->toBe([['6100', 100_000, 0], ['1300', 12_000, 0], ['2210', 0, 5_000], ['2000', 0, 107_000]])
        ->and(apEntry('vendor_payment_made', 'PV-2026-0001'))->toBe([['2000', 107_000, 0], ['1000', 0, 107_000]])
        ->and(apBalance('2000'))->toBe(0)
        ->and(apBalance('6100'))->toBe(100_000)
        ->and(apChecks())->each->toBeTrue();

    // A vendor that is not VAT-registered gives no input tax: the whole bill is the expense. `withholding: false` withholds nothing.
    $sundry = $this->postJson('/api/v1/vendors', ['name' => 'Aling Nena Sari-Sari', 'is_vat_registered' => false])->assertCreated()->json('data.id');
    $plain = apBill(['vendor_id' => $sundry, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'OR 1182', 'withholding' => false, 'lines' => [['kind' => 'expense', 'account_id' => apAccount('6120'), 'description' => 'Rags and soap', 'quantity' => '3', 'unit_cost_cents' => 4_550]]])->assertCreated()->json('data');
    expect($plain['totals'])->toMatchArray(['non_vat_purchases_cents' => 13_650, 'vatable_purchases_cents' => 0, 'input_vat_cents' => 0, 'total_cents' => 13_650])
        ->and($plain['withholding'])->toBeNull()
        ->and(apEntry('vendor_bill_posted', 'VB-2026-0002'))->toBe([['6120', 13_650, 0], ['2000', 0, 13_650]]);

    // A vendor with bills is part of the books: deactivated, never removed.
    $this->deleteJson("/api/v1/vendors/{$sundry}")->assertStatus(409)->assertJsonPath('error.details.reason', 'vendor_in_use');
});

it('refuses a bill that does not add up to something the books can hold', function () {
    apAs();
    $landlord = apLandlord();
    $rent = apAccount('6100');
    $line = fn (array $over = []): array => $over + ['kind' => 'expense', 'account_id' => $rent, 'description' => 'Rent', 'unit_cost_cents' => 10_000];

    // A control account, or one that is not an expense or an asset, is not charged by hand.
    apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'X1', 'lines' => [$line(['account_id' => apAccount('1100')])]])
        ->assertStatus(422)->assertJsonPath('error.details.fields', fn (array $f): bool => str_contains(json_encode($f), 'control account'));
    apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'X2', 'lines' => [$line(['account_id' => apAccount('4000')])]])
        ->assertStatus(422)->assertJsonPath('error.details.fields', fn (array $f): bool => str_contains(json_encode($f), 'expense or an asset'));
    apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'X3', 'bill_date' => '2026-10-09', 'lines' => [$line()]])->assertStatus(422)->assertJsonPath('error.details.fields.bill_date.0', 'A bill is never dated in the future.');
    apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'X4', 'withholding' => ['atc_code' => 'RENT', 'rate_pct' => '5'], 'lines' => [$line()]])->assertStatus(422);
    apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'X5', 'lines' => []])->assertStatus(422);
    // The key is demanded: a bill is never recorded twice by a retry.
    $this->postJson('/api/v1/vendor-bills', ['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'X6', 'lines' => [$line()]])->assertStatus(422);
    expect(asSystem(fn () => VendorBill::query()->where('organization_id', $this->world->id('prov-mekanikomore'))->count()))->toBe(0);

    // A payment is not dated before the bill, pays no more than is owed, and names its reference unless cash.
    $bill = apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'X7', 'withholding' => false, 'lines' => [$line()]])->assertCreated()->json('data');
    apPay(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'method' => 'cash', 'paid_on' => '2026-10-07', 'amount_cents' => 1_000])->assertStatus(422)->assertJsonPath('error.details.fields.paid_on.0', fn (string $m): bool => str_contains($m, 'VB-2026-0001'));
    apPay(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'method' => 'check', 'amount_cents' => 1_000])->assertStatus(422)->assertJsonPath('error.details.fields.reference_no.0', 'A Check payment needs its reference number.');
    apPay(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'method' => 'cash', 'allocations' => [['vendor_bill_id' => $bill['id'], 'amount_cents' => $bill['payable_cents'] + 1]]])->assertStatus(422);
    apPay(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'method' => 'cash', 'allocations' => [['vendor_bill_id' => $bill['id'], 'amount_cents' => $bill['payable_cents']]]])->assertCreated()->assertJsonPath('data.amount_cents', $bill['payable_cents']);
    $this->getJson("/api/v1/vendor-bills/{$bill['id']}")->assertJsonPath('data.status', 'paid');
});

it('ages what vendors are owed, as of a date', function () {
    apAs();
    $landlord = apLandlord();
    $line = fn (int $cents): array => ['kind' => 'expense', 'account_id' => apAccount('6100'), 'description' => 'Rent', 'unit_cost_cents' => $cents];
    // Billed 45 days ago on 30-day terms: 15 days past due today. And one billed today.
    apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'AUG', 'bill_date' => '2026-08-24', 'withholding' => false, 'lines' => [$line(100_000)]])->assertCreated();
    apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'OCT', 'withholding' => false, 'lines' => [$line(50_000)]])->assertCreated();
    apPay(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'method' => 'cash', 'amount_cents' => 40_000])->assertCreated()->assertJsonPath('data.allocations.0.bill_number', 'VB-2026-0001');

    $aging = $this->getJson('/api/v1/payables/aging')->assertOk()->json('data');
    expect($aging['vendors'])->toHaveCount(1)
        ->and($aging['vendors'][0])->toMatchArray(['vendor_name' => 'Biñan Commercial Leasing', 'current' => 56_000, 'days_1_30' => 112_000 - 40_000, 'total' => 168_000 - 40_000])
        ->and($aging['totals']['total'])->toBe(128_000);
    // Before the payment and before the second bill existed.
    expect($this->getJson('/api/v1/payables/aging?as_of=2026-09-30')->json('data.totals'))->toMatchArray(['days_1_30' => 112_000, 'total' => 112_000])
        ->and($this->getJson('/api/v1/vendor-bills?status=overdue')->json('data.*.number'))->toBe(['VB-2026-0001'])
        ->and($this->getJson('/api/v1/vendor-bills?status=open')->json('data.*.number'))->toBe(['VB-2026-0002', 'VB-2026-0001'])
        ->and($this->getJson('/api/v1/vendor-payments')->json('data.*.number'))->toBe(['PV-2026-0001']);
});

it('keeps payables to the staff who hold them, inside their own branches', function () {
    apAs();
    $landlord = apLandlord();
    $bill = apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'R-1', 'withholding' => false, 'lines' => [['kind' => 'expense', 'account_id' => apAccount('6100'), 'description' => 'Rent', 'unit_cost_cents' => 10_000]]])->assertCreated()->json('data');
    $body = ['vendor_id' => $landlord, 'vendor_invoice_no' => 'R-2', 'withholding' => false, 'lines' => [['kind' => 'expense', 'account_id' => apAccount('6100'), 'description' => 'Rent', 'unit_cost_cents' => 10_000]]];

    // The cashier and the advisor hold no payables capability; a portal user is refused outright.
    foreach (['cashier@mekanikomore.ph', 'advisor@mekanikomore.ph', 'donmiguel@mekanikomor.ph'] as $email) {
        apAs($email);
        $this->getJson('/api/v1/vendor-bills')->assertForbidden();
        $this->getJson('/api/v1/payables/aging')->assertForbidden();
        $this->getJson('/api/v1/vendor-payments')->assertForbidden();
        apBill($body + ['branch_id' => $this->repair])->assertStatus($email === 'donmiguel@mekanikomor.ph' ? 403 : 403);
    }
    apAs('donmiguel@mekanikomor.ph');
    $this->getJson("/api/v1/vendor-bills/{$bill['id']}")->assertNotFound();

    // The detailing branch's manager records bills for their own branch, and never sees the repair branch's.
    apAs('manager.samahuzai@mekanikomore.ph');
    $this->getJson("/api/v1/vendor-bills/{$bill['id']}")->assertNotFound();
    expect($this->getJson('/api/v1/vendor-bills')->assertOk()->json('data'))->toBe([]);
    apBill($body + ['branch_id' => $this->repair])->assertNotFound();
    $own = apBill($body)->assertCreated()->json('data');
    expect($own['branch_id'])->toBe($this->world->id('samahuzai-binan'))->and($own['number'])->toBe('VB-2026-0002');
    $this->getJson('/api/v1/vendor-bills/'.$this->world->id('rival:vendor-bill'))->assertNotFound();
    $this->getJson('/api/v1/vendor-payments/'.$this->world->id('rival:vendor-payment'))->assertNotFound();
});

it('holds a posted bill and its payment still in the database, and their sums at commit', function () {
    apAs();
    $landlord = apLandlord();
    $bill = apBill(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'R-1', 'withholding' => false, 'lines' => [['kind' => 'expense', 'account_id' => apAccount('6100'), 'description' => 'Rent', 'unit_cost_cents' => 10_000]]])->assertCreated()->json('data');
    $payment = apPay(['vendor_id' => $landlord, 'branch_id' => $this->repair, 'method' => 'cash', 'amount_cents' => 4_000])->assertCreated()->json('data');

    $refused = function (string $sql): string {
        try {
            DB::transaction(fn () => DB::statement($sql));
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        return 'accepted';
    };
    expect($refused("update vendor_bills set total_cents = total_cents + 1 where id = '{$bill['id']}'"))->toBe('23001')
        ->and($refused("delete from vendor_bills where id = '{$bill['id']}'"))->toBe('23001')
        ->and($refused("update vendor_bill_lines set net_cents = 1 where vendor_bill_id = '{$bill['id']}'"))->toBe('23001')
        ->and($refused("update vendor_payments set amount_cents = 1 where id = '{$payment['id']}'"))->toBe('23001')
        ->and($refused("delete from vendor_payment_allocations where vendor_payment_id = '{$payment['id']}'"))->toBe('23001');

    // paid_cents is what the standing payments allocate: a figure that disagrees fails the commit.
    expect(fn () => DB::transaction(function () use ($bill): void {
        DB::statement("update vendor_bills set paid_cents = 5000, status = 'partially_paid' where id = '{$bill['id']}'");
        DB::statement('set constraints all immediate');
    }))->toThrow(QueryException::class);
});
