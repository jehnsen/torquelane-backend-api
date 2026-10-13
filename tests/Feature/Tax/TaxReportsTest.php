<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Tax working papers (Phase 12) over the demo seed (today 2026-10-08, VAT 12%
 * on top). Sales: INV-2026-0001 (Actimed, 2026-07-25, ₱8,585.00 + ₱1,030.20),
 * INV-2026-0002 (Northwind, 2026-08-19, ₱4,710.00 + ₱565.20), INV-2026-0003
 * (Actimed, 2026-09-18, ₱1,865.00 + ₱223.80). No purchases are billed yet.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->repair = $this->world->id('mekanikomor-binan');
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
});

function txAccount(string $code): string
{
    return collect(test()->getJson('/api/v1/ledger/accounts')->assertOk()->json('data'))->firstWhere('code', $code)['id'];
}

/** A VAT-registered contractor the shop withholds 2% from, billed ₱10,000.00 + VAT for September's work. */
function txContractor(): array
{
    $vendor = test()->postJson('/api/v1/vendors', [
        'name' => 'Laguna Aircon Services',
        'registered_name' => 'Laguna Aircon Services Inc.',
        'tin' => '221-904-336',
        'address' => 'Sta. Rosa, Laguna',
        'default_atc_code' => 'WC120',
        'default_withholding_rate_pct' => '2',
    ])->assertCreated()->json('data.id');
    $bill = test()->postJson('/api/v1/vendor-bills', [
        'vendor_id' => $vendor,
        'branch_id' => test()->repair,
        'vendor_invoice_no' => 'LAS-0457',
        'bill_date' => '2026-09-22',
        'lines' => [['kind' => 'expense', 'account_id' => txAccount('6000'), 'description' => 'Compressor overhaul', 'unit_cost_cents' => 1_000_000]],
    ], ['Idempotency-Key' => 'tx-bill'])->assertCreated()->json('data');

    return ['vendor' => $vendor, 'bill' => $bill];
}

it('adds up the VAT position from the documents, and it is the VAT accounts\' own movement', function () {
    $quarter = $this->getJson('/api/v1/tax/vat?year=2026&quarter=3')->assertOk()->json('data');
    expect($quarter['from'])->toBe('2026-07-01')
        ->and($quarter['to'])->toBe('2026-09-30')
        ->and($quarter['sales'])->toMatchArray(['documents' => 3, 'vatable_cents' => 858_500 + 471_000 + 186_500, 'vat_cents' => 103_020 + 56_520 + 22_380, 'gross_cents' => 961_520 + 527_520 + 208_880])
        ->and($quarter['purchases']['documents'])->toBe(0)
        ->and($quarter['output_vat_cents'])->toBe(181_920)
        ->and($quarter['input_vat_cents'])->toBe(0)
        ->and($quarter['vat_payable_cents'])->toBe(181_920)
        ->and($quarter['ledger'])->toBe(['output_vat_cents' => 181_920, 'input_vat_cents' => 0]);

    // A purchase in the quarter brings input tax; a credit note in October takes October's output tax down.
    txContractor();
    $this->postJson('/api/v1/invoices/'.$this->world->id('invoice:actimed-current').'/credit-notes', ['reason' => 'Goodwill', 'lines' => [['description' => 'Allowance', 'unit_price_cents' => 10_000]]], ['Idempotency-Key' => 'tx-cn'])->assertCreated();

    $third = $this->getJson('/api/v1/tax/vat?from=2026-07-01&to=2026-09-30')->assertOk()->json('data');
    expect($third['purchases'])->toMatchArray(['documents' => 1, 'vatable_cents' => 1_000_000, 'vat_cents' => 120_000, 'gross_cents' => 1_120_000])
        ->and($third['vat_payable_cents'])->toBe(181_920 - 120_000)
        ->and($third['ledger'])->toBe(['output_vat_cents' => 181_920, 'input_vat_cents' => 120_000]);
    $october = $this->getJson('/api/v1/tax/vat?from=2026-10-01&to=2026-10-08')->assertOk()->json('data');
    expect($october['sales'])->toMatchArray(['documents' => 1, 'vatable_cents' => -10_000, 'vat_cents' => -1_200])
        ->and($october['vat_payable_cents'])->toBe(-1_200)
        ->and($october['ledger']['output_vat_cents'])->toBe(-1_200);

    // No period given: the quarter today falls in.
    expect($this->getJson('/api/v1/tax/vat')->assertOk()->json('data'))->toMatchArray(['from' => '2026-10-01', 'to' => '2026-12-31']);
    $this->getJson('/api/v1/tax/vat?from=2026-10-01&to=2026-09-01')->assertStatus(422);
});

it('lists sales by customer and purchases by vendor, as data and as a spreadsheet', function () {
    ['vendor' => $vendor] = txContractor();

    $sales = $this->getJson('/api/v1/tax/sales?year=2026&quarter=3')->assertOk()->json('data');
    expect($sales['rows'])->toHaveCount(2)
        // Largest first: Actimed's two invoices, then Northwind's one.
        ->and($sales['rows'][0])->toMatchArray(['party_id' => $this->world->id('fc-actimed'), 'documents' => 2, 'vatable_cents' => 858_500 + 186_500, 'vat_cents' => 103_020 + 22_380, 'gross_cents' => 961_520 + 208_880])
        ->and($sales['rows'][1])->toMatchArray(['party_id' => $this->world->id('fc-northwind'), 'documents' => 1, 'gross_cents' => 527_520])
        ->and($sales['totals']['gross_cents'])->toBe(961_520 + 527_520 + 208_880);

    $purchases = $this->getJson('/api/v1/tax/purchases?year=2026&quarter=3')->assertOk()->json('data');
    expect($purchases['rows'])->toHaveCount(1)
        ->and($purchases['rows'][0])->toMatchArray(['party_id' => $vendor, 'party_name' => 'Laguna Aircon Services Inc.', 'tin' => '221-904-336', 'address' => 'Sta. Rosa, Laguna', 'vatable_cents' => 1_000_000, 'vat_cents' => 120_000]);

    $csv = $this->get('/api/v1/tax/purchases?year=2026&quarter=3&file=csv')->assertOk();
    expect($csv->headers->get('Content-Disposition'))->toContain('summary-list-of-purchases-2026-07-01-to-2026-09-30.csv')
        ->and($csv->getContent())->toContain('Laguna Aircon Services Inc.')->toContain('221-904-336')->toContain('10000.00')->toContain('1200.00');
    expect($this->get('/api/v1/tax/sales?year=2026&quarter=3&file=csv')->assertOk()->getContent())->toContain('Registered name');
});

it('reports the tax withheld from vendors and prints a vendor\'s 2307 by month', function () {
    ['vendor' => $vendor, 'bill' => $bill] = txContractor();
    expect($bill['withholding'])->toBe(['atc_code' => 'WC120', 'rate_pct' => '2', 'tax_base_cents' => 1_000_000, 'amount_cents' => 20_000])
        ->and($bill['payable_cents'])->toBe(1_120_000 - 20_000);

    $alphalist = $this->getJson('/api/v1/tax/withholding/expanded?year=2026&quarter=3')->assertOk()->json('data');
    expect($alphalist['rows'])->toBe([[
        'party_id' => $vendor, 'party_name' => 'Laguna Aircon Services Inc.', 'tin' => '221-904-336', 'address' => 'Sta. Rosa, Laguna',
        'atc_code' => 'WC120', 'rate_pct' => '2', 'tax_base_cents' => 1_000_000, 'tax_withheld_cents' => 20_000, 'documents' => 1,
    ]])->and($alphalist['totals'])->toBe(['tax_base_cents' => 1_000_000, 'tax_withheld_cents' => 20_000]);
    expect($this->get('/api/v1/tax/withholding/expanded?year=2026&quarter=3&file=csv')->assertOk()->getContent())->toContain('WC120')->toContain('200.00');

    // September is the third month of the third quarter.
    $form = $this->getJson("/api/v1/tax/withholding/certificates/{$vendor}?year=2026&quarter=3")->assertOk()->json('data');
    expect($form['form'])->toBe('2307')
        ->and($form['period'])->toMatchArray(['year' => 2026, 'quarter' => 3, 'from' => '2026-07-01', 'to' => '2026-09-30', 'label' => 'Q3 2026'])
        ->and($form['payee'])->toMatchArray(['name' => 'Laguna Aircon Services Inc.', 'tin' => '221-904-336'])
        ->and($form['payor']['name'])->not->toBe('')
        ->and($form['rows'])->toBe([['atc_code' => 'WC120', 'rate_pct' => '2', 'month_1_cents' => 0, 'month_2_cents' => 0, 'month_3_cents' => 1_000_000, 'total_cents' => 1_000_000, 'tax_withheld_cents' => 20_000]])
        ->and($form['totals']['tax_withheld_cents'])->toBe(20_000)
        ->and($form['documents'][0])->toMatchArray(['date' => '2026-09-22', 'reference' => 'VB-2026-0001']);

    // Voiding the bill in October takes the withholding back in October, not out of September.
    $this->postJson("/api/v1/vendor-bills/{$bill['id']}/void", ['reason' => 'Billed twice'])->assertOk();
    expect($this->getJson('/api/v1/tax/withholding/expanded?year=2026&quarter=3')->json('data.totals.tax_withheld_cents'))->toBe(20_000)
        ->and($this->getJson('/api/v1/tax/withholding/expanded?year=2026&quarter=4')->json('data.rows.0'))->toMatchArray(['tax_base_cents' => -1_000_000, 'tax_withheld_cents' => -20_000])
        ->and($this->getJson('/api/v1/tax/vat?year=2026&quarter=4')->json('data.purchases.vat_cents'))->toBe(-120_000);
});

it('lists the tax customers withheld, and keeps the papers to those who hold the books', function () {
    $this->postJson('/api/v1/invoices/'.$this->world->id('invoice:northwind').'/withholding', ['atc_code' => 'WC160', 'rate_pct' => '2', 'certificate_no' => '2307-NW-0091'])->assertCreated();

    $sawt = $this->getJson('/api/v1/tax/withholding/creditable?year=2026&quarter=4')->assertOk()->json('data');
    expect($sawt['rows'])->toHaveCount(1)
        ->and($sawt['rows'][0])->toMatchArray(['party_id' => $this->world->id('fc-northwind'), 'atc_code' => 'WC160', 'rate_pct' => '2', 'tax_base_cents' => 471_000, 'tax_withheld_cents' => 9_420])
        ->and($this->getJson('/api/v1/tax/withholding/creditable?year=2026&quarter=3')->json('data.rows'))->toBe([]);

    $options = $this->getJson('/api/v1/tax/options')->assertOk()->json('data.atc_codes');
    expect(collect($options)->firstWhere('code', 'WC160'))->toMatchArray(['rate_pct' => '2']);

    // A branch manager reads their own branch's papers (Samahuzai has billed nothing); others are refused.
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    expect($this->getJson('/api/v1/tax/vat?year=2026&quarter=3')->assertOk()->json('data.sales.documents'))->toBe(0)
        ->and($this->getJson('/api/v1/tax/withholding/creditable?year=2026&quarter=4')->json('data.rows'))->toBe([]);
    foreach (['cashier@mekanikomore.ph', 'advisor@mekanikomore.ph', 'donmiguel@mekanikomor.ph'] as $email) {
        Sanctum::actingAs($this->world->user($email));
        foreach (['options', 'vat', 'sales', 'purchases', 'withholding/expanded', 'withholding/creditable'] as $path) {
            $this->getJson("/api/v1/tax/{$path}")->assertForbidden();
        }
    }
});
