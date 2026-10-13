<?php

declare(strict_types=1);

namespace App\Domain\Payables;

use App\Domain\Billing\Billing;
use App\Domain\Invoicing\VatTreatment;
use App\Domain\Ledger\Apportion;
use App\Domain\Shared\Calendar;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * A vendor bill's arithmetic: the mirror image of an invoice's (R6, one
 * rounding per total, never per line).
 *
 *  - the vendor's prices EXCLUSIVE of VAT: input VAT = round(VATable × rate /
 *    100), on top;
 *  - INCLUSIVE: input VAT = round(VATable gross × rate / (100 + rate)),
 *    extracted, and VATable purchases are the gross less it;
 *  - VAT-exempt and zero-rated lines carry none either way;
 *  - a vendor that is not VAT-registered charges none: what it sells us falls
 *    in no VAT category (`non_vat_purchases`) and gives no input tax to claim.
 *
 * Expanded withholding is taken on what was bought BEFORE VAT:
 * round(net purchases × rate / 100), and comes off what is paid to the vendor.
 *
 * Each line's net (what it debits) is its share of its bucket's net, split by
 * largest remainder so the lines always add up to the stored totals.
 */
final class Payables
{
    /**
     * @param  list<BillLineDraft>  $lines
     * @param  string|null  $withholdingRatePct  decimal string; null = nothing withheld
     */
    public static function totals(array $lines, VatTreatment $vat, ?string $withholdingRatePct = null): BillTotals
    {
        $gross = ['vatable' => 0, 'vat_exempt' => 0, 'zero_rated' => 0];
        /** @var array<string, int> $vatableWeights line key → total */
        $vatableWeights = [];
        foreach ($lines as $position => $line) {
            $total = $line->totalCents();
            $gross[$line->taxClass->value] += $total;
            if ($line->taxClass->value === 'vatable') {
                $vatableWeights['l'.$position] = $total;
            }
        }

        if (! $vat->vatRegistered) {
            $net = array_sum($gross);

            return self::withWithholding(0, 0, 0, $net, 0, $net, array_map(fn (BillLineDraft $line): int => $line->totalCents(), $lines), $withholdingRatePct);
        }

        $rate = $vat->effectiveRate();
        $vatable = $gross['vatable'];
        if ($vat->pricesIncludeVat) {
            $tax = Billing::roundCents(BigRational::of($vatable)->multipliedBy($rate)->dividedBy($rate->plus(100)));
            $vatableNet = $vatable - $tax;
        } else {
            $tax = Billing::roundCents(BigRational::of($vatable)->multipliedBy($rate)->dividedBy(100));
            $vatableNet = $vatable;
        }

        $shares = $vatableWeights === [] ? [] : Apportion::split($vatableNet, $vatableWeights);
        $byLine = [];
        foreach ($lines as $position => $line) {
            $byLine[] = $line->taxClass->value === 'vatable' ? ($shares['l'.$position] ?? 0) : $line->totalCents();
        }

        return self::withWithholding(
            $vatableNet,
            $gross['vat_exempt'],
            $gross['zero_rated'],
            0,
            $tax,
            $vatableNet + $tax + $gross['vat_exempt'] + $gross['zero_rated'],
            $byLine,
            $withholdingRatePct,
        );
    }

    /** What is withheld on a tax base, rounded once, half up. */
    public static function withholding(int $baseCents, string $ratePct): int
    {
        return Billing::roundCents(BigRational::of($baseCents)->multipliedBy(BigDecimal::of($ratePct))->dividedBy(100));
    }

    /** Bill date + the vendor's terms, in calendar days (Manila). */
    public static function dueDate(string $billDate, int $paymentTermsDays): string
    {
        return Calendar::toDate(Calendar::addDays(Calendar::parseDate($billDate), max(0, $paymentTermsDays)));
    }

    /**
     * @param  list<int>  $byLine
     */
    private static function withWithholding(int $vatable, int $exempt, int $zeroRated, int $nonVat, int $tax, int $total, array $byLine, ?string $ratePct): BillTotals
    {
        $base = $vatable + $exempt + $zeroRated + $nonVat;
        $withheld = $ratePct === null ? 0 : self::withholding($base, $ratePct);

        return new BillTotals($vatable, $exempt, $zeroRated, $nonVat, $tax, $total, $ratePct === null ? 0 : $base, $withheld, $byLine);
    }
}
