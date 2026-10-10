<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Export;

/** The journal as the accountant's own file: one row per line, debits and credits in their own columns. */
final class JournalCsv
{
    public const array HEADER = ['Entry', 'Date', 'Event', 'Reference', 'Memo', 'Branch', 'Account code', 'Account', 'Debit', 'Credit', 'Customer', 'Line memo', 'Reverses'];

    /**
     * @param  list<ExportLine>  $lines
     * @return list<list<string>>
     */
    public static function rows(array $lines): array
    {
        return array_map(fn (ExportLine $line): array => [
            $line->entryNumber,
            $line->entryDate,
            $line->event,
            $line->reference,
            $line->memo,
            $line->branchName,
            $line->accountCode,
            $line->accountName,
            $line->debitCents === 0 ? '' : Pesos::plain($line->debitCents),
            $line->creditCents === 0 ? '' : Pesos::plain($line->creditCents),
            $line->customerName,
            $line->lineMemo,
            $line->reversalOf,
        ], $lines);
    }
}
