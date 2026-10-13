<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tax\TaxQueries;
use App\Domain\PurchaseOrders\ExportTable;
use App\Domain\Tax\Atc;
use App\Exports\SpreadsheetWriter;
use App\Http\Requests\TaxReportRequest;
use App\Models\Account;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Tax working papers (Phase 12), all `ledger:view` and all over the caller's
 * branches: the VAT position, the summary lists of sales and purchases, the
 * two withholding alphalists and a vendor's 2307. A period is `from`..`to`
 * or a calendar quarter (`year`, `quarter`); the default is this quarter.
 * They read the documents; nothing here files a return, and no layout here
 * is claimed to be BIR-accredited.
 */
final class TaxReportController
{
    /**
     * Tax options
     *
     * The ATC pick-list (code, usual rate, what it covers). Any well-formed
     * code is accepted with the rate given: this list is a convenience.
     */
    public function options(): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return new JsonResponse(['data' => ['atc_codes' => Atc::common()]]);
    }

    /**
     * VAT position
     *
     * Sales by VAT category with output VAT, purchases with input VAT, and
     * VAT payable (negative: excess input tax). A void or a credit note
     * counts as a negative on the day it happened. `ledger` is the movement
     * of the Output and Input VAT accounts over the same period.
     */
    public function vat(TaxReportRequest $request, TaxQueries $tax): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return new JsonResponse(['data' => $tax->vat($request->from(), $request->to())]);
    }

    /**
     * Summary list of sales
     *
     * One row per customer: sales by VAT category, output VAT, gross.
     * `file=csv` downloads it.
     */
    public function sales(TaxReportRequest $request, TaxQueries $tax): JsonResponse|Response
    {
        Gate::authorize('viewAny', Account::class);
        $list = $tax->salesList($request->from(), $request->to());

        return $request->wantsCsv() ? $this->summaryCsv('Sales', 'summary-list-of-sales', $list, $request) : new JsonResponse(['data' => $list]);
    }

    /**
     * Summary list of purchases
     *
     * One row per vendor: purchases by VAT category, input VAT, gross.
     * `file=csv` downloads it.
     */
    public function purchases(TaxReportRequest $request, TaxQueries $tax): JsonResponse|Response
    {
        Gate::authorize('viewAny', Account::class);
        $list = $tax->purchasesList($request->from(), $request->to());

        return $request->wantsCsv() ? $this->summaryCsv('Purchases', 'summary-list-of-purchases', $list, $request) : new JsonResponse(['data' => $list]);
    }

    /**
     * Tax withheld from vendors
     *
     * Expanded withholding the shop took off vendor bills, one row per
     * vendor and ATC: the alphalist of payees behind form 1601-EQ.
     * `file=csv` downloads it.
     */
    public function expanded(TaxReportRequest $request, TaxQueries $tax): JsonResponse|Response
    {
        Gate::authorize('viewAny', Account::class);
        $list = $tax->withheldFromVendors($request->from(), $request->to());

        return $request->wantsCsv() ? $this->alphalistCsv('Payees', 'alphalist-of-payees', $list, $request) : new JsonResponse(['data' => $list]);
    }

    /**
     * Tax withheld by customers
     *
     * The 2307s customers handed over, one row per customer and ATC: the
     * summary alphalist of withholding taxes behind the shop's tax credit.
     * `file=csv` downloads it.
     */
    public function creditable(TaxReportRequest $request, TaxQueries $tax): JsonResponse|Response
    {
        Gate::authorize('viewAny', Account::class);
        $list = $tax->withheldByCustomers($request->from(), $request->to());

        return $request->wantsCsv() ? $this->alphalistCsv('Withheld by customers', 'summary-alphalist-of-withholding', $list, $request) : new JsonResponse(['data' => $list]);
    }

    /**
     * A vendor's 2307
     *
     * What BIR form 2307 prints for one vendor and quarter (`year`,
     * `quarter`; default this quarter): payor, payee, and per ATC the income
     * payments of each month with the tax withheld. The payor is the branch
     * selected (`X-Branch-Id`), else the organization.
     */
    public function certificate(TaxReportRequest $request, Vendor $vendor, TaxQueries $tax): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);
        Gate::authorize('view', $vendor);

        return new JsonResponse(['data' => $tax->certificate($vendor, $request->quarter())]);
    }

    /**
     * @param  array<string, mixed>  $list
     */
    private function summaryCsv(string $sheet, string $name, array $list, TaxReportRequest $request): Response
    {
        $rows = [];
        foreach (self::rows($list) as $row) {
            $rows[] = [self::text($row, 'tin'), self::text($row, 'party_name'), self::text($row, 'address'), self::cents($row, 'vatable_cents'), self::cents($row, 'vat_exempt_cents'), self::cents($row, 'zero_rated_cents'), self::cents($row, 'non_vat_cents'), self::cents($row, 'vat_cents'), self::cents($row, 'gross_cents')];
        }

        return $this->download(new ExportTable($sheet, ['TIN', 'Registered name', 'Address', 'VATable', 'VAT-exempt', 'Zero-rated', 'Non-VAT', 'VAT', 'Gross'], [3, 4, 5, 6, 7, 8], $rows, [14, 36, 40, 16, 16, 16, 16, 16, 16]), $name, $request);
    }

    /**
     * @param  array<string, mixed>  $list
     */
    private function alphalistCsv(string $sheet, string $name, array $list, TaxReportRequest $request): Response
    {
        $rows = [];
        foreach (self::rows($list) as $row) {
            $rows[] = [self::text($row, 'tin'), self::text($row, 'party_name'), self::text($row, 'address'), self::text($row, 'atc_code'), self::text($row, 'rate_pct'), self::cents($row, 'tax_base_cents'), self::cents($row, 'tax_withheld_cents')];
        }

        return $this->download(new ExportTable($sheet, ['TIN', 'Registered name', 'Address', 'ATC', 'Rate %', 'Income payment', 'Tax withheld'], [5, 6], $rows, [14, 36, 40, 8, 8, 16, 16]), $name, $request);
    }

    private function download(ExportTable $table, string $name, TaxReportRequest $request): Response
    {
        return new Response(SpreadsheetWriter::csv($table), 200, [
            'Content-Type' => SpreadsheetWriter::CSV_TYPE,
            'Content-Disposition' => sprintf('attachment; filename="%s-%s-to-%s.csv"', $name, $request->from(), $request->to()),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param  array<string, mixed>  $list
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $list): array
    {
        $rows = [];
        foreach (is_array($list['rows'] ?? null) ? $list['rows'] : [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function text(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function cents(array $row, string $key): int
    {
        return is_int($row[$key] ?? null) ? $row[$key] : 0;
    }
}
