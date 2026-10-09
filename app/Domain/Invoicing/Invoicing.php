<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Billing\Billing;
use App\Domain\Inventory\TaxClass;
use App\Domain\Shared\Calendar;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * Building an invoice: its lines from closed work orders, its totals under the
 * branch's VAT treatment, and its due date.
 *
 * VAT (R6, one rounding per total, never per line):
 *  - prices EXCLUSIVE of VAT (the branch's `prices_include_vat` false):
 *    VAT = round(VATable gross × rate / 100), added on top;
 *  - prices INCLUSIVE of VAT: VAT = round(VATable gross × rate / (100 + rate)),
 *    extracted, and VATable sales are the gross less that VAT;
 *  - VAT-exempt and zero-rated lines carry no VAT either way;
 *  - a branch that is not VAT-registered charges no VAT, sorts its sales into
 *    no VAT category (`non_vat_sales`), and prints NON_VAT_NOTICE.
 *
 * Discounts come off each line before tax (a VAT-inclusive branch's discount
 * is VAT-inclusive too).
 */
final class Invoicing
{
    /** What an invoice of a branch that is not VAT-registered must say. */
    public const string NON_VAT_NOTICE = 'THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.';

    /**
     * @param  list<InvoiceLineDraft>  $lines
     */
    public static function totals(array $lines, VatTreatment $vat): InvoiceTotals
    {
        $gross = ['vatable' => 0, 'vat_exempt' => 0, 'zero_rated' => 0];
        $discounts = 0;
        foreach ($lines as $line) {
            $gross[$line->taxClass->value] += $line->totalCents();
            $discounts += $line->discountCents;
        }

        if (! $vat->vatRegistered) {
            $sales = array_sum($gross);

            return new InvoiceTotals(0, 0, 0, $sales, $discounts, 0, $sales);
        }

        $rate = $vat->effectiveRate();
        $vatable = $gross['vatable'];
        if ($vat->pricesIncludeVat) {
            $tax = Billing::roundCents(BigRational::of($vatable)->multipliedBy($rate)->dividedBy($rate->plus(100)));
            $vatableSales = $vatable - $tax;
        } else {
            $tax = Billing::roundCents(BigRational::of($vatable)->multipliedBy($rate)->dividedBy(100));
            $vatableSales = $vatable;
        }

        return new InvoiceTotals(
            $vatableSales,
            $gross['vat_exempt'],
            $gross['zero_rated'],
            0,
            $discounts,
            $tax,
            $vatableSales + $tax + $gross['vat_exempt'] + $gross['zero_rated'],
        );
    }

    /**
     * Lines for one or many closed jobs of an account, in job order: for each
     * approved line its parts (at the line's part rate) and its labour (at
     * its labour rate) as two lines, either left out when it bills nothing;
     * then the job's flat misc fee, if any. Every line is VATable but parts of
     * a shop-stock item, which carry the item's tax class.
     *
     * @param  list<BillableJob>  $jobs
     * @return list<InvoiceLineDraft>
     */
    public static function linesFromJobs(array $jobs): array
    {
        $lines = [];
        foreach ($jobs as $job) {
            foreach ($job->lines as $line) {
                $both = $line->partCostCents > 0 && $line->labourCostCents > 0;
                if ($line->partCostCents > 0) {
                    $lines[] = new InvoiceLineDraft(
                        InvoiceLineKind::Parts,
                        $both ? "{$line->description} — parts" : $line->description,
                        $line->quantity,
                        $line->unitPartRateCents,
                        0,
                        $line->partsTaxClass,
                        $job->id,
                        $line->id,
                        $line->itemId,
                        $line->serviceTaskId,
                    );
                }
                if ($line->labourCostCents > 0) {
                    $lines[] = new InvoiceLineDraft(
                        InvoiceLineKind::Labour,
                        $both ? "{$line->description} — labour" : $line->description,
                        $line->labourHours,
                        $line->labourRateCents,
                        0,
                        TaxClass::Vatable,
                        $job->id,
                        $line->id,
                        null,
                        $line->serviceTaskId,
                    );
                }
            }
            if ($job->miscFeeCents > 0) {
                $lines[] = new InvoiceLineDraft(InvoiceLineKind::Fee, "Shop supplies and sundries ({$job->reference})", '1', $job->miscFeeCents, 0, TaxClass::Vatable, $job->id);
            }
        }

        return $lines;
    }

    /**
     * What the lines bill before tax: each line's total (rounded once), summed.
     *
     * @param  list<InvoiceLineDraft>  $lines
     */
    public static function grossOf(array $lines): int
    {
        return array_sum(array_map(fn (InvoiceLineDraft $line): int => $line->totalCents(), $lines));
    }

    /** Issue date + the account's payment terms, in calendar days (Manila). */
    public static function dueDate(string $issueDate, int $paymentTermsDays): string
    {
        return Calendar::toDate(Calendar::addDays(Calendar::parseDate($issueDate), max(0, $paymentTermsDays)));
    }

    /** A quantity as stored (numeric 14,3) and printed: "1", "1.5", "0.25". */
    public static function quantity(string $quantity): string
    {
        return (string) BigDecimal::of($quantity)->strippedOfTrailingZeros();
    }
}
