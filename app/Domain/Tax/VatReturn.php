<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * The VAT position of a period, the figures behind BIR form 2550Q, and the
 * two summary lists that go with it (SLS: sales by customer; SLP: purchases
 * by vendor). Nothing here files anything: it adds up the documents so the
 * accountant can.
 *
 * VAT payable = output VAT on sales − input VAT on purchases. A negative
 * figure is excess input tax, carried to the next period by the accountant.
 */
final class VatReturn
{
    /**
     * @param  list<TaxableDocument>  $sales
     * @param  list<TaxableDocument>  $purchases
     * @return array{sales: array<string, int>, purchases: array<string, int>, output_vat_cents: int, input_vat_cents: int, vat_payable_cents: int}
     */
    public static function summarize(array $sales, array $purchases): array
    {
        $out = self::totals($sales);
        $in = self::totals($purchases);

        return [
            'sales' => $out,
            'purchases' => $in,
            'output_vat_cents' => $out['vat_cents'],
            'input_vat_cents' => $in['vat_cents'],
            'vat_payable_cents' => $out['vat_cents'] - $in['vat_cents'],
        ];
    }

    /**
     * One row per party (by TIN where there is one, else by the party
     * itself), largest first: the summary list of sales or of purchases.
     *
     * @param  list<TaxableDocument>  $documents
     * @return array{rows: list<array{party_id: string, party_name: string, tin: string|null, address: string|null, documents: int, vatable_cents: int, vat_exempt_cents: int, zero_rated_cents: int, non_vat_cents: int, vat_cents: int, gross_cents: int}>, totals: array<string, int>}
     */
    public static function summaryList(array $documents): array
    {
        $rows = [];
        foreach ($documents as $document) {
            $key = $document->partyId;
            $rows[$key] ??= [
                'party_id' => $document->partyId,
                'party_name' => $document->partyName,
                'tin' => $document->partyTin,
                'address' => $document->partyAddress,
                'documents' => 0,
                'vatable_cents' => 0,
                'vat_exempt_cents' => 0,
                'zero_rated_cents' => 0,
                'non_vat_cents' => 0,
                'vat_cents' => 0,
                'gross_cents' => 0,
            ];
            $rows[$key]['documents']++;
            $rows[$key]['vatable_cents'] += $document->vatableCents;
            $rows[$key]['vat_exempt_cents'] += $document->vatExemptCents;
            $rows[$key]['zero_rated_cents'] += $document->zeroRatedCents;
            $rows[$key]['non_vat_cents'] += $document->nonVatCents;
            $rows[$key]['vat_cents'] += $document->vatCents;
            $rows[$key]['gross_cents'] += $document->grossCents();
        }
        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => [$b['gross_cents'], $a['party_name']] <=> [$a['gross_cents'], $b['party_name']]);

        return ['rows' => $rows, 'totals' => self::totals($documents)];
    }

    /**
     * @param  list<TaxableDocument>  $documents
     * @return array{documents: int, vatable_cents: int, vat_exempt_cents: int, zero_rated_cents: int, non_vat_cents: int, net_cents: int, vat_cents: int, gross_cents: int}
     */
    public static function totals(array $documents): array
    {
        $totals = ['documents' => count($documents), 'vatable_cents' => 0, 'vat_exempt_cents' => 0, 'zero_rated_cents' => 0, 'non_vat_cents' => 0, 'net_cents' => 0, 'vat_cents' => 0, 'gross_cents' => 0];
        foreach ($documents as $document) {
            $totals['vatable_cents'] += $document->vatableCents;
            $totals['vat_exempt_cents'] += $document->vatExemptCents;
            $totals['zero_rated_cents'] += $document->zeroRatedCents;
            $totals['non_vat_cents'] += $document->nonVatCents;
            $totals['net_cents'] += $document->netCents();
            $totals['vat_cents'] += $document->vatCents;
            $totals['gross_cents'] += $document->grossCents();
        }

        return $totals;
    }
}
