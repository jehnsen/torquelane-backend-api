<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Export;

use DateTimeImmutable;

/**
 * Xero's manual-journal import: one row per line, grouped into a journal by
 * the narration and date; debits positive, credits negative; the account by
 * its Xero code. The narration leads with the entry number so two journals
 * never share one. Layout per Xero's published manual-journal template;
 * confirm it against the current template before go-live.
 */
final class XeroManualJournal
{
    public const array HEADER = ['*Narration', '*Date', 'Description', '*AccountCode', '*TaxRate', '*Amount', 'TrackingName1', 'TrackingOption1'];

    /**
     * @param  list<ExportLine>  $lines
     * @param  array<string, string>  $codes  our account code → the Xero account code
     * @return list<list<string>>
     */
    public static function rows(array $lines, array $codes, string $taxRate): array
    {
        return array_map(function (ExportLine $line) use ($codes, $taxRate): array {
            $amount = $line->debitCents - $line->creditCents;

            return [
                trim("{$line->entryNumber} {$line->memo}"),
                self::date($line->entryDate),
                $line->lineMemo !== '' ? $line->lineMemo : $line->reference,
                $codes[$line->accountCode] ?? '',
                $taxRate,
                Pesos::plain($amount),
                $line->branchName !== '' ? 'Branch' : '',
                $line->branchName,
            ];
        }, $lines);
    }

    private static function date(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : $parsed->format('d/m/Y');
    }
}
