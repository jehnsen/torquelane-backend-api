<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Approvals\LineApprovalStatus;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Port of ../web/lib/billing.ts in integer centavos (R6). The TypeScript
 * rounds IEEE floats with Math.round(x × 100) / 100; here every product is
 * exact and rounded half-up once, to a whole centavo:
 *
 *  - a line's part and labour amounts, once each (they are stored);
 *  - each total, once — never a sum of rounded intermediates beyond the
 *    stored line amounts.
 *
 * Where the float arithmetic lands on the other side of a half centavo, the
 * golden replay lists the case by name as a pinned divergence.
 */
final class Billing
{
    /** Any exact amount in centavos → whole centavos, half-up (away from zero). */
    public static function roundCents(BigNumber|int|string $centavos): int
    {
        return BigNumber::of($centavos)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    public static function linePartCents(string $quantity, int $unitPartRateCents): int
    {
        return self::roundCents(BigDecimal::of($quantity)->multipliedBy($unitPartRateCents));
    }

    public static function lineLabourCents(string $labourHours, int $labourRateCents): int
    {
        return self::roundCents(BigDecimal::of($labourHours)->multipliedBy($labourRateCents));
    }

    /** What the rates bill (../web lineAmount). */
    public static function lineCents(BillableLine $line): int
    {
        return self::linePartCents($line->quantity, $line->unitPartRateCents)
            + self::lineLabourCents($line->labourHours, $line->labourRateCents);
    }

    /** The only writer of a line's stored costs: whenever a quantity or rate changes. */
    public static function recalc(BillableLine $line): BillableLine
    {
        return new BillableLine(
            $line->quantity,
            $line->unitPartRateCents,
            $line->labourHours,
            $line->labourRateCents,
            self::linePartCents($line->quantity, $line->unitPartRateCents),
            self::lineLabourCents($line->labourHours, $line->labourRateCents),
            $line->approvalStatus,
            $line->partsSource,
        );
    }

    /**
     * Rates for a line stored before rates existed: one unit at the stored
     * part cost, and labour as one flat hour (none if it billed none) — so
     * the reconstruction reproduces the stored total without a backfill guess.
     *
     * @return array{quantity: string, unit_part_rate_cents: int, labour_hours: string, labour_rate_cents: int}
     */
    public static function withRates(int $partCostCents, int $labourCostCents, ?string $quantity, ?int $unitPartRateCents, ?string $labourHours, ?int $labourRateCents): array
    {
        $quantity ??= '1';
        $hours = $labourHours ?? ($labourCostCents > 0 ? '1' : '0');
        $positive = fn (string $n): bool => BigDecimal::of($n)->isPositive();

        return [
            'quantity' => $quantity,
            'unit_part_rate_cents' => $unitPartRateCents
                ?? ($positive($quantity) ? self::roundCents(BigRational::of($partCostCents)->dividedBy($quantity)) : 0),
            'labour_hours' => $hours,
            'labour_rate_cents' => $labourRateCents
                ?? ($positive($hours) ? self::roundCents(BigRational::of($labourCostCents)->dividedBy($hours)) : 0),
        ];
    }

    /**
     * VAT goes on top of the pre-tax subtotal plus the flat misc fee
     * (prices exclusive of VAT; inclusive pricing arrives with invoicing).
     *
     * @param  list<BillableLine>  $lines
     * @param  list<LineApprovalStatus>|null  $statuses  count only these lines; null counts all
     */
    public static function totals(array $lines, string $vatRatePct, int $miscFeeCents, ?array $statuses = null): BillingTotals
    {
        $counted = $statuses === null
            ? $lines
            : array_values(array_filter($lines, fn (BillableLine $line): bool => in_array($line->approvalStatus, $statuses, true)));
        if ($counted === []) {
            return BillingTotals::zero($vatRatePct);
        }

        $parts = 0;
        $labour = 0;
        foreach ($counted as $line) {
            $parts += self::linePartCents($line->quantity, $line->unitPartRateCents);
            $labour += self::lineLabourCents($line->labourHours, $line->labourRateCents);
        }

        return self::assemble($parts, $labour, $vatRatePct, $miscFeeCents);
    }

    /** The same bill from aggregate figures (an order with no lines). Nothing billed → nothing charged. */
    public static function totalsFromSubtotal(int $partsCents, int $labourCents, string $vatRatePct, int $miscFeeCents): BillingTotals
    {
        if ($partsCents + $labourCents === 0) {
            return BillingTotals::zero($vatRatePct);
        }

        return self::assemble($partsCents, $labourCents, $vatRatePct, $miscFeeCents);
    }

    /**
     * @param  list<BillableLine>  $lines
     */
    public static function approvedGrandTotal(array $lines, string $vatRatePct, int $miscFeeCents): int
    {
        return self::totals($lines, $vatRatePct, $miscFeeCents, [LineApprovalStatus::Approved])->grandTotalCents;
    }

    private static function assemble(int $parts, int $labour, string $vatRatePct, int $misc): BillingTotals
    {
        $sub = $parts + $labour;
        $tax = self::roundCents(BigRational::of($sub + $misc)->multipliedBy($vatRatePct)->dividedBy(100));

        return new BillingTotals($parts, $labour, $sub, $misc, $tax, $vatRatePct, $sub + $misc + $tax);
    }
}
