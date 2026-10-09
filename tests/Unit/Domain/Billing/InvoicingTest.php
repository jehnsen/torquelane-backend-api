<?php

declare(strict_types=1);

use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\BillableJob;
use App\Domain\Invoicing\BillableJobLine;
use App\Domain\Invoicing\InvoiceLineDraft;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Invoicing\VatTreatment;

/** A manual line: quantity × unit price (centavos), less a discount. */
function invLine(string $quantity, int $unit, TaxClass $class = TaxClass::Vatable, int $discount = 0): InvoiceLineDraft
{
    return new InvoiceLineDraft(InvoiceLineKind::Manual, 'Line', $quantity, $unit, $discount, $class);
}

const VAT_EXCLUSIVE = [true, false, '12'];
const VAT_INCLUSIVE = [true, true, '12'];
const NON_VAT = [false, false, '12'];

/*
 * Table-driven VAT cases: [lines, [registered, inclusive, rate],
 * [vatable, exempt, zero, non-VAT, discounts, VAT, total due]] in centavos.
 */
dataset('vat cases', fn (): array => [
    'exclusive: VAT added on top' => [[invLine('1', 100_000)], VAT_EXCLUSIVE, [100_000, 0, 0, 0, 0, 12_000, 112_000]],
    'inclusive: VAT extracted from the price' => [[invLine('1', 112_000)], VAT_INCLUSIVE, [100_000, 0, 0, 0, 0, 12_000, 112_000]],
    'inclusive: the extracted VAT rounds half-up, once' => [[invLine('1', 10_000)], VAT_INCLUSIVE, [8_929, 0, 0, 0, 0, 1_071, 10_000]],
    'exclusive: one rounding per total, never per line' => [[invLine('1', 10), invLine('1', 10), invLine('1', 10)], VAT_EXCLUSIVE, [30, 0, 0, 0, 0, 4, 34]],
    'inclusive: one rounding per total, never per line' => [[invLine('1', 50), invLine('1', 50)], VAT_INCLUSIVE, [89, 0, 0, 0, 0, 11, 100]],
    'zero-rated carries no VAT (exclusive)' => [[invLine('1', 50_000, TaxClass::ZeroRated)], VAT_EXCLUSIVE, [0, 0, 50_000, 0, 0, 0, 50_000]],
    'zero-rated carries no VAT (inclusive)' => [[invLine('1', 50_000, TaxClass::ZeroRated)], VAT_INCLUSIVE, [0, 0, 50_000, 0, 0, 0, 50_000]],
    'VAT-exempt carries no VAT (exclusive)' => [[invLine('2', 10_000, TaxClass::VatExempt)], VAT_EXCLUSIVE, [0, 20_000, 0, 0, 0, 0, 20_000]],
    'VAT-exempt carries no VAT (inclusive)' => [[invLine('2', 10_000, TaxClass::VatExempt)], VAT_INCLUSIVE, [0, 20_000, 0, 0, 0, 0, 20_000]],
    'mixed, exclusive' => [[invLine('1', 100_000), invLine('1', 20_000, TaxClass::VatExempt), invLine('1', 30_000, TaxClass::ZeroRated)], VAT_EXCLUSIVE, [100_000, 20_000, 30_000, 0, 0, 12_000, 162_000]],
    'mixed, inclusive' => [[invLine('1', 112_000), invLine('1', 20_000, TaxClass::VatExempt), invLine('1', 30_000, TaxClass::ZeroRated)], VAT_INCLUSIVE, [100_000, 20_000, 30_000, 0, 0, 12_000, 162_000]],
    'not VAT-registered: no VAT, no VAT categories' => [[invLine('1', 100_000), invLine('1', 20_000, TaxClass::VatExempt), invLine('1', 30_000, TaxClass::ZeroRated)], NON_VAT, [0, 0, 0, 150_000, 0, 0, 150_000]],
    'not VAT-registered and inclusive pricing: still no VAT' => [[invLine('1', 112_000)], [false, true, '12'], [0, 0, 0, 112_000, 0, 0, 112_000]],
    'discount before tax (exclusive)' => [[invLine('1', 100_000, TaxClass::Vatable, 10_000)], VAT_EXCLUSIVE, [90_000, 0, 0, 0, 10_000, 10_800, 100_800]],
    'discount before tax (inclusive: the discount includes VAT)' => [[invLine('1', 112_000, TaxClass::Vatable, 11_200)], VAT_INCLUSIVE, [90_000, 0, 0, 0, 11_200, 10_800, 100_800]],
    'a fractional quantity rounds the line once, half-up' => [[invLine('1.5', 33_333)], VAT_EXCLUSIVE, [50_000, 0, 0, 0, 0, 6_000, 56_000]],
    'a 0% rate (VAT-registered) charges none' => [[invLine('1', 100_000)], [true, false, '0'], [100_000, 0, 0, 0, 0, 0, 100_000]],
    'a decimal rate' => [[invLine('1', 100_000)], [true, false, '7.5'], [100_000, 0, 0, 0, 0, 7_500, 107_500]],
]);

it('totals an invoice under the branch\'s VAT treatment', function (array $lines, array $vat, array $expected) {
    $totals = Invoicing::totals($lines, new VatTreatment(...$vat));

    expect([
        $totals->vatableSalesCents,
        $totals->vatExemptSalesCents,
        $totals->zeroRatedSalesCents,
        $totals->nonVatSalesCents,
        $totals->discountTotalCents,
        $totals->vatAmountCents,
        $totals->totalDueCents,
    ])->toBe($expected)
        ->and($totals->totalDueCents)->toBe($totals->vatableSalesCents + $totals->vatAmountCents + $totals->vatExemptSalesCents + $totals->zeroRatedSalesCents + $totals->nonVatSalesCents);
})->with('vat cases');

it('refuses a line it cannot bill', function () {
    expect(fn () => invLine('0', 100))->toThrow(InvalidArgumentException::class)
        ->and(fn () => invLine('1', -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => invLine('1', 100, TaxClass::Vatable, 101))->toThrow(InvalidArgumentException::class, 'A discount cannot exceed the line it is on.');
});

it('bills each approved job line\'s parts and labour at its stored rates, then the job\'s fee', function () {
    $jobs = [
        new BillableJob('wo-1', 'WO-2026-0001', 'PMS', [
            new BillableJobLine('l-1', 'Oil change', '1', 250_000, 250_000, '1.5', 65_000, 97_500, 'task-oil'),
            new BillableJobLine('l-2', 'Brake inspection', '0', 0, 0, '0.5', 65_000, 32_500),
            new BillableJobLine('l-3', 'Customer pads', '2', 0, 0, '0', 65_000, 0),
            new BillableJobLine('l-4', 'Wiper blades', '1', 82_000, 82_000, '0', 0, 0, null, 'item-wiper', TaxClass::ZeroRated),
        ], 15_000),
        new BillableJob('wo-2', 'WO-2026-0002', 'Tyres', [new BillableJobLine('l-5', 'Rotate tyres', '1', 0, 0, '1', 50_000, 50_000)], 0),
    ];

    $lines = Invoicing::linesFromJobs($jobs);

    expect(array_map(fn (InvoiceLineDraft $l): array => [$l->kind->value, $l->description, $l->quantity, $l->unitPriceCents, $l->totalCents(), $l->taxClass->value, $l->workOrderId, $l->workOrderLineId], $lines))->toBe([
        ['parts', 'Oil change — parts', '1', 250_000, 250_000, 'vatable', 'wo-1', 'l-1'],
        ['labour', 'Oil change — labour', '1.5', 65_000, 97_500, 'vatable', 'wo-1', 'l-1'],
        ['labour', 'Brake inspection', '0.5', 65_000, 32_500, 'vatable', 'wo-1', 'l-2'],
        ['parts', 'Wiper blades', '1', 82_000, 82_000, 'zero_rated', 'wo-1', 'l-4'],
        ['fee', 'Shop supplies and sundries (WO-2026-0001)', '1', 15_000, 15_000, 'vatable', 'wo-1', null],
        ['labour', 'Rotate tyres', '1', 50_000, 50_000, 'vatable', 'wo-2', 'l-5'],
    ])
        // Every line reproduces the stored approved cost exactly (R11).
        ->and(Invoicing::grossOf($lines))->toBe(250_000 + 97_500 + 32_500 + 82_000 + 15_000 + 50_000);
});

it('bills a job exactly as its approved total under exclusive VAT', function () {
    // The work order's approved grand total: (sub + misc) + round((sub + misc) × 12%).
    $job = new BillableJob('wo-1', 'WO-2026-0001', 'PMS', [new BillableJobLine('l-1', 'Oil change', '1', 250_005, 250_005, '1', 65_000, 65_000)], 15_000);
    $totals = Invoicing::totals(Invoicing::linesFromJobs([$job]), new VatTreatment(true, false, '12'));

    expect($totals->totalDueCents)->toBe(330_005 + 39_601);
});

it('dates an invoice due by the account\'s payment terms, in Manila calendar days', function () {
    expect(Invoicing::dueDate('2026-10-09', 30))->toBe('2026-11-08')
        ->and(Invoicing::dueDate('2026-10-09', 0))->toBe('2026-10-09')
        ->and(Invoicing::dueDate('2026-12-31', 1))->toBe('2027-01-01')
        ->and(Invoicing::dueDate('2028-02-28', 1))->toBe('2028-02-29');
});

it('derives an issued invoice\'s status from what is paid', function () {
    expect(InvoiceStatus::settled(0, 10_000))->toBe(InvoiceStatus::Issued)
        ->and(InvoiceStatus::settled(1, 10_000))->toBe(InvoiceStatus::PartiallyPaid)
        ->and(InvoiceStatus::settled(10_000, 10_000))->toBe(InvoiceStatus::Paid)
        ->and(InvoiceStatus::settled(0, 0))->toBe(InvoiceStatus::Paid)
        ->and(InvoiceStatus::Issued->isOpen())->toBeTrue()
        ->and(InvoiceStatus::PartiallyPaid->isOpen())->toBeTrue()
        ->and(InvoiceStatus::Paid->isOpen())->toBeFalse()
        ->and(InvoiceStatus::Draft->isStanding())->toBeFalse()
        ->and(InvoiceStatus::Void->isStanding())->toBeFalse()
        ->and(InvoiceStatus::Paid->isStanding())->toBeTrue();
});

it('prints quantities without trailing zeros', function () {
    expect(Invoicing::quantity('1.000'))->toBe('1')
        ->and(Invoicing::quantity('1.500'))->toBe('1.5')
        ->and(Invoicing::quantity('0.250'))->toBe('0.25');
});
