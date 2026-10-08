<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrders;

/**
 * A spreadsheet as data: headings, rows, and which columns hold money (as
 * integer centavos, formatted by the writer — never a float).
 */
final readonly class ExportTable
{
    /**
     * @param  list<string>  $headings
     * @param  list<int>  $moneyColumns  zero-based column indexes
     * @param  list<list<string|int|null>>  $rows  null = an empty cell
     * @param  list<int>  $widths  column widths in characters
     */
    public function __construct(
        public string $sheet,
        public array $headings,
        public array $moneyColumns,
        public array $rows,
        public array $widths,
    ) {}
}
