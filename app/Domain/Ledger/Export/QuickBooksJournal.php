<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Export;

use DateTimeImmutable;

/**
 * QuickBooks Online's journal-entry import: rows sharing a journal number
 * make one entry; debits and credits in their own columns; the account by
 * its QuickBooks name; the branch as the Location. Layout per QuickBooks'
 * published journal-entry template; confirm it against the current template
 * before go-live.
 */
final class QuickBooksJournal
{
    public const array HEADER = ['Journal No', 'Journal Date', 'Account Name', 'Debits', 'Credits', 'Description', 'Name', 'Location'];

    /**
     * @param  list<ExportLine>  $lines
     * @param  array<string, string>  $names  our account code → the QuickBooks account name
     * @return list<list<string>>
     */
    public static function rows(array $lines, array $names): array
    {
        return array_map(function (ExportLine $line) use ($names): array {
            return [
                $line->entryNumber,
                self::date($line->entryDate),
                $names[$line->accountCode] ?? '',
                $line->debitCents === 0 ? '' : Pesos::plain($line->debitCents),
                $line->creditCents === 0 ? '' : Pesos::plain($line->creditCents),
                trim($line->memo.($line->lineMemo !== '' ? ' — '.$line->lineMemo : '')),
                '',
                $line->branchName,
            ];
        }, $lines);
    }

    private static function date(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : $parsed->format('m/d/Y');
    }
}
