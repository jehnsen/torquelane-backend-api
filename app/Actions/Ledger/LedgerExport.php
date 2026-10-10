<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Database\Cell;
use App\Domain\Ledger\Export\ExportLine;
use App\Domain\Ledger\Export\JournalCsv;
use App\Domain\Ledger\Export\QuickBooksJournal;
use App\Domain\Ledger\Export\XeroManualJournal;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\AccountExportMapping;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Tenancy\TenantManager;

/**
 * The journal as files: always the plain CSV; and, when the organization's
 * books are kept in Xero or QuickBooks Online (`organizations.accounting_target`),
 * that product's manual-journal import. A file never leaves with an account
 * the mapping table has no code (Xero) or name (QuickBooks) for: it is refused
 * and the unmapped accounts are named. No live sync.
 */
final class LedgerExport
{
    public const array FORMATS = ['csv', 'xero', 'quickbooks'];

    /** Xero requires a tax rate on each manual-journal row; VAT is posted to its own accounts, so none is applied on top. */
    public const string XERO_TAX_RATE = 'Tax Exempt';

    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * @return array{filename: string, rows: list<list<string>>, headings: list<string>}
     */
    public function build(string $format, string $from, string $to): array
    {
        $organization = Organization::query()->findOrFail($this->tenancy->require()->organizationId());
        if ($format !== 'csv' && $format !== $organization->accounting_target) {
            throw new ConflictException(
                $organization->accounting_target === 'none'
                    ? 'This organization has not chosen an accounting product to export to; set one in the ledger settings.'
                    : "This organization's books are kept in {$organization->accounting_target}, not {$format}.",
                ['reason' => 'export_target_mismatch', 'accounting_target' => $organization->accounting_target],
            );
        }

        $lines = $this->lines($from, $to);

        return match ($format) {
            'xero' => [
                'filename' => "journal-xero-{$from}-{$to}.csv",
                'headings' => XeroManualJournal::HEADER,
                'rows' => XeroManualJournal::rows($lines, $this->mapping($lines, 'xero'), self::XERO_TAX_RATE),
            ],
            'quickbooks' => [
                'filename' => "journal-quickbooks-{$from}-{$to}.csv",
                'headings' => QuickBooksJournal::HEADER,
                'rows' => QuickBooksJournal::rows($lines, $this->mapping($lines, 'quickbooks')),
            ],
            default => [
                'filename' => "journal-{$from}-{$to}.csv",
                'headings' => JournalCsv::HEADER,
                'rows' => JournalCsv::rows($lines),
            ],
        };
    }

    /**
     * @return list<ExportLine> entries in the caller's branches, dated within the range, in number order
     */
    public function lines(string $from, string $to): array
    {
        $entries = JournalEntry::query()->visibleTo($this->tenancy->require())
            ->whereBetween('entry_date', [$from, $to])->orderBy('entry_date')->orderBy('number')->with('lines')->get();
        $accounts = Account::query()->get()->keyBy('id');
        $branches = Branch::query()->pluck('name', 'id');
        $reversed = [];
        $customerIds = [];
        foreach ($entries as $entry) {
            if ($entry->reversal_of_id !== null) {
                $reversed[] = $entry->reversal_of_id;
            }
            foreach ($entry->lines as $line) {
                if ($line->customer_account_id !== null) {
                    $customerIds[] = $line->customer_account_id;
                }
            }
        }
        $originals = JournalEntry::query()->whereIn('id', $reversed)->pluck('number', 'id');
        $customers = CustomerAccount::query()->whereIn('id', array_unique($customerIds))->pluck('display_name', 'id');

        $context = $this->tenancy->require();
        $lines = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                // A caller pinned to a branch exports only that branch's lines, so the file is theirs alone.
                if ($context->branchRestricted && ! in_array($line->branch_id, $context->allowedBranchIds, true)) {
                    continue;
                }
                $account = $accounts->get($line->account_id);
                if ($account === null) {
                    continue;
                }
                $lines[] = new ExportLine(
                    $entry->number,
                    $entry->entry_date->toDateString(),
                    $entry->event->label(),
                    $entry->reference,
                    $entry->memo,
                    Cell::string($branches[$line->branch_id] ?? null),
                    $account->code,
                    $account->name,
                    $line->debit_cents,
                    $line->credit_cents,
                    $line->customer_account_id === null ? '' : Cell::string($customers[$line->customer_account_id] ?? null),
                    $line->memo,
                    $entry->reversal_of_id === null ? '' : Cell::string($originals[$entry->reversal_of_id] ?? null),
                );
            }
        }

        return $lines;
    }

    /**
     * Our account code → the product's code (Xero) or name (QuickBooks), for every account the file uses.
     *
     * @param  list<ExportLine>  $lines
     * @return array<string, string>
     */
    private function mapping(array $lines, string $target): array
    {
        $used = array_values(array_unique(array_map(fn (ExportLine $l): string => $l->accountCode, $lines)));
        $accounts = Account::query()->whereIn('code', $used)->get()->keyBy('id');
        $column = $target === 'xero' ? 'external_code' : 'external_name';

        $map = [];
        foreach (AccountExportMapping::query()->where('target', $target)->whereIn('account_id', $accounts->keys()->all())->get() as $row) {
            $value = $row->{$column};
            $account = $accounts->get($row->account_id);
            if ($account !== null && is_string($value) && $value !== '') {
                $map[$account->code] = $value;
            }
        }

        $missing = array_values(array_filter($used, fn (string $code): bool => ! isset($map[$code])));
        if ($missing !== []) {
            sort($missing);
            throw new ConflictException(
                sprintf('Map these accounts to %s before exporting: %s.', $target === 'xero' ? 'Xero account codes' : 'QuickBooks account names', implode(', ', $missing)),
                ['reason' => 'unmapped_accounts', 'account_codes' => $missing, 'target' => $target],
            );
        }

        return $map;
    }
}
