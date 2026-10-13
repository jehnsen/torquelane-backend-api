<?php

declare(strict_types=1);

use App\Domain\Tax\Atc;
use App\Domain\Tax\Quarter;
use App\Domain\Tax\TaxableDocument;
use App\Domain\Tax\VatReturn;
use App\Domain\Tax\WithheldAmount;
use App\Domain\Tax\WithholdingSummary;

/* The tax working papers' arithmetic (Phase 12), without a database. */

it('knows a calendar quarter: its bounds and which of its months a date is', function () {
    expect(Quarter::of('2026-09-22'))->toEqual(new Quarter(2026, 3))
        ->and((new Quarter(2026, 1))->startsOn())->toBe('2026-01-01')
        ->and((new Quarter(2026, 1))->endsOn())->toBe('2026-03-31')
        ->and((new Quarter(2026, 2))->endsOn())->toBe('2026-06-30')
        ->and((new Quarter(2026, 3))->endsOn())->toBe('2026-09-30')
        ->and((new Quarter(2026, 4))->endsOn())->toBe('2026-12-31')
        ->and((new Quarter(2026, 3))->monthOf('2026-07-01'))->toBe(1)
        ->and((new Quarter(2026, 3))->monthOf('2026-08-15'))->toBe(2)
        ->and((new Quarter(2026, 3))->monthOf('2026-09-30'))->toBe(3)
        ->and((new Quarter(2026, 4))->label())->toBe('Q4 2026')
        ->and(fn () => (new Quarter(2026, 3))->monthOf('2026-10-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Quarter(2026, 5))->toThrow(InvalidArgumentException::class);
});

it('accepts a well-formed ATC and nothing else', function () {
    expect(Atc::isValid('WC158'))->toBeTrue()
        ->and(Atc::isValid('WI010'))->toBeTrue()
        ->and(Atc::isValid('wc158'))->toBeFalse()
        ->and(Atc::isValid('WX158'))->toBeFalse()
        ->and(Atc::isValid('WC15'))->toBeFalse()
        ->and(collect(Atc::common())->every(fn (array $row): bool => Atc::isValid($row['code'])))->toBeTrue();
});

it('adds up a VAT period, with voids and credit notes as negatives on their own day', function () {
    $invoice = new TaxableDocument('2026-09-18', 'invoice', 'INV-1', 'acct-1', 'Actimed', '111-222-333', 'Biñan', 100_000, 5_000, 0, 0, 12_000);
    $credit = new TaxableDocument('2026-09-20', 'credit_note', 'CN-1', 'acct-1', 'Actimed', '111-222-333', 'Biñan', -10_000, 0, 0, 0, -1_200);
    $other = new TaxableDocument('2026-09-25', 'invoice', 'INV-2', 'acct-2', 'Northwind', null, null, 0, 0, 0, 20_000, 0);
    $bill = new TaxableDocument('2026-09-22', 'vendor_bill', 'VB-1', 'vendor-1', 'Parts Co', '444-555-666', null, 50_000, 0, 0, 0, 6_000);

    expect($invoice->netCents())->toBe(105_000)
        ->and($invoice->grossCents())->toBe(117_000)
        ->and($invoice->reversed('2026-10-02', 'invoice_void')->grossCents())->toBe(-117_000);

    $return = VatReturn::summarize([$invoice, $credit, $other], [$bill, $bill->reversed('2026-09-30', 'vendor_bill_void')]);
    expect($return['sales'])->toBe(['documents' => 3, 'vatable_cents' => 90_000, 'vat_exempt_cents' => 5_000, 'zero_rated_cents' => 0, 'non_vat_cents' => 20_000, 'net_cents' => 115_000, 'vat_cents' => 10_800, 'gross_cents' => 125_800])
        ->and($return['output_vat_cents'])->toBe(10_800)
        ->and($return['input_vat_cents'])->toBe(0)
        ->and($return['vat_payable_cents'])->toBe(10_800);

    // One row per party, largest first; a party's documents net against each other.
    $list = VatReturn::summaryList([$other, $invoice, $credit]);
    expect(array_column($list['rows'], 'party_name'))->toBe(['Actimed', 'Northwind'])
        ->and($list['rows'][0])->toMatchArray(['documents' => 2, 'vatable_cents' => 90_000, 'vat_cents' => 10_800, 'gross_cents' => 105_800, 'tin' => '111-222-333']);
});

it('lays withholding out as an alphalist and as a 2307 by month', function () {
    $amount = fn (string $date, string $party, string $atc, string $rate, int $base, int $tax): WithheldAmount => new WithheldAmount($date, $party, ucfirst($party), '111-222-333', 'Laguna', $atc, $rate, $base, $tax, 'VB');
    $amounts = [
        $amount('2026-07-10', 'beta', 'WC120', '2', 100_000, 2_000),
        $amount('2026-09-22', 'beta', 'WC120', '2', 50_000, 1_000),
        $amount('2026-08-05', 'beta', 'WC100', '5', 20_000, 1_000),
        $amount('2026-08-05', 'alpha', 'WC158', '1', 300_000, 3_000),
        // A bill voided in the period nets itself out and drops off the list.
        $amount('2026-08-06', 'gamma', 'WC158', '1', 10_000, 100),
        $amount('2026-08-07', 'gamma', 'WC158', '1', -10_000, -100),
    ];

    $alphalist = WithholdingSummary::alphalist($amounts);
    expect(array_map(fn (array $r): array => [$r['party_name'], $r['atc_code'], $r['tax_base_cents'], $r['tax_withheld_cents'], $r['documents']], $alphalist['rows']))->toBe([
        ['Alpha', 'WC158', 300_000, 3_000, 1],
        ['Beta', 'WC100', 20_000, 1_000, 1],
        ['Beta', 'WC120', 150_000, 3_000, 2],
    ])->and($alphalist['totals'])->toBe(['tax_base_cents' => 470_000, 'tax_withheld_cents' => 7_000]);

    $form = WithholdingSummary::certificate(array_values(array_filter($amounts, fn (WithheldAmount $a): bool => $a->partyId === 'beta')), new Quarter(2026, 3));
    expect($form['rows'])->toBe([
        ['atc_code' => 'WC100', 'rate_pct' => '5', 'month_1_cents' => 0, 'month_2_cents' => 20_000, 'month_3_cents' => 0, 'total_cents' => 20_000, 'tax_withheld_cents' => 1_000],
        ['atc_code' => 'WC120', 'rate_pct' => '2', 'month_1_cents' => 100_000, 'month_2_cents' => 0, 'month_3_cents' => 50_000, 'total_cents' => 150_000, 'tax_withheld_cents' => 3_000],
    ])->and($form['totals'])->toBe(['month_1_cents' => 100_000, 'month_2_cents' => 20_000, 'month_3_cents' => 50_000, 'total_cents' => 170_000, 'tax_withheld_cents' => 4_000]);
});
