<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * Withholding added up the two ways BIR asks for it:
 *
 *  - `alphalist`: one row per party and ATC over a period (the QAP of payees
 *    behind form 1601-EQ for what the shop withheld; the SAWT behind the
 *    income tax return for what customers withheld from the shop);
 *  - `certificate`: one party's quarter laid out as BIR form 2307 prints it,
 *    one row per ATC with the income payments of the quarter's first, second
 *    and third month in their own columns.
 */
final class WithholdingSummary
{
    /**
     * @param  list<WithheldAmount>  $amounts
     * @return array{rows: list<array{party_id: string, party_name: string, tin: string|null, address: string|null, atc_code: string, rate_pct: string, tax_base_cents: int, tax_withheld_cents: int, documents: int}>, totals: array{tax_base_cents: int, tax_withheld_cents: int}}
     */
    public static function alphalist(array $amounts): array
    {
        $rows = [];
        foreach ($amounts as $amount) {
            $key = $amount->partyId.'|'.$amount->atcCode.'|'.$amount->ratePct;
            $rows[$key] ??= [
                'party_id' => $amount->partyId,
                'party_name' => $amount->partyName,
                'tin' => $amount->partyTin,
                'address' => $amount->partyAddress,
                'atc_code' => $amount->atcCode,
                'rate_pct' => $amount->ratePct,
                'tax_base_cents' => 0,
                'tax_withheld_cents' => 0,
                'documents' => 0,
            ];
            $rows[$key]['tax_base_cents'] += $amount->taxBaseCents;
            $rows[$key]['tax_withheld_cents'] += $amount->taxWithheldCents;
            $rows[$key]['documents']++;
        }
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['tax_base_cents'] !== 0 || $row['tax_withheld_cents'] !== 0));
        usort($rows, fn (array $a, array $b): int => [$a['party_name'], $a['atc_code'], $a['rate_pct']] <=> [$b['party_name'], $b['atc_code'], $b['rate_pct']]);

        return [
            'rows' => $rows,
            'totals' => [
                'tax_base_cents' => array_sum(array_column($rows, 'tax_base_cents')),
                'tax_withheld_cents' => array_sum(array_column($rows, 'tax_withheld_cents')),
            ],
        ];
    }

    /**
     * @param  list<WithheldAmount>  $amounts  one party's, all dated inside the quarter
     * @return array{rows: list<array{atc_code: string, rate_pct: string, month_1_cents: int, month_2_cents: int, month_3_cents: int, total_cents: int, tax_withheld_cents: int}>, totals: array{month_1_cents: int, month_2_cents: int, month_3_cents: int, total_cents: int, tax_withheld_cents: int}}
     */
    public static function certificate(array $amounts, Quarter $quarter): array
    {
        $rows = [];
        foreach ($amounts as $amount) {
            $key = $amount->atcCode.'|'.$amount->ratePct;
            $month = $quarter->monthOf($amount->date);
            $row = $rows[$key] ?? ['atc_code' => $amount->atcCode, 'rate_pct' => $amount->ratePct, 'month_1_cents' => 0, 'month_2_cents' => 0, 'month_3_cents' => 0, 'total_cents' => 0, 'tax_withheld_cents' => 0];
            $row['month_1_cents'] += $month === 1 ? $amount->taxBaseCents : 0;
            $row['month_2_cents'] += $month === 2 ? $amount->taxBaseCents : 0;
            $row['month_3_cents'] += $month === 3 ? $amount->taxBaseCents : 0;
            $row['total_cents'] += $amount->taxBaseCents;
            $row['tax_withheld_cents'] += $amount->taxWithheldCents;
            $rows[$key] = $row;
        }
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['total_cents'] !== 0 || $row['tax_withheld_cents'] !== 0));
        usort($rows, fn (array $a, array $b): int => [$a['atc_code'], $a['rate_pct']] <=> [$b['atc_code'], $b['rate_pct']]);

        $totals = ['month_1_cents' => 0, 'month_2_cents' => 0, 'month_3_cents' => 0, 'total_cents' => 0, 'tax_withheld_cents' => 0];
        foreach ($rows as $row) {
            $totals['month_1_cents'] += $row['month_1_cents'];
            $totals['month_2_cents'] += $row['month_2_cents'];
            $totals['month_3_cents'] += $row['month_3_cents'];
            $totals['total_cents'] += $row['total_cents'];
            $totals['tax_withheld_cents'] += $row['tax_withheld_cents'];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
