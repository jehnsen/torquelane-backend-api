<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ledger\LedgerExport;
use App\Actions\Ledger\LedgerQueries;
use App\Domain\PurchaseOrders\ExportTable;
use App\Exports\SpreadsheetWriter;
use App\Http\Requests\ExportJournalRequest;
use App\Http\Requests\ListJournalRequest;
use App\Http\Resources\JournalEntryCollection;
use App\Http\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The journal (Phase 8): browse, open one entry, export. Entries are made by
 * the money and stock actions, never by a request, and are never edited or
 * deleted: a void posts a reversing entry.
 */
final class JournalController
{
    /**
     * Journal
     *
     * `ledger:view`. Entries in the caller's branches, newest first, filtered
     * by date range, account, event, branch, source document or a search on
     * number, reference and memo.
     */
    public function index(ListJournalRequest $request, LedgerQueries $ledger): JournalEntryCollection
    {
        Gate::authorize('viewAny', JournalEntry::class);

        return new JournalEntryCollection($ledger->journalPage($request->filters(), $request->perPage()));
    }

    /**
     * Show a journal entry
     */
    public function show(JournalEntry $journalEntry, LedgerQueries $ledger): JournalEntryResource
    {
        Gate::authorize('view', $journalEntry);

        return new JournalEntryResource($ledger->entry($journalEntry));
    }

    /**
     * Export the journal
     *
     * `ledger:view`. `format` `csv` (default): one row per line. `xero` or
     * `quickbooks`: that product's manual-journal import, when it is the
     * organization's accounting target and every account used is mapped
     * (409 `unmapped_accounts` names the ones that are not). A caller limited
     * to some branches exports only those branches' lines. No live sync.
     */
    public function export(ExportJournalRequest $request, LedgerExport $export): Response
    {
        Gate::authorize('viewAny', JournalEntry::class);
        $file = $export->build($request->exportFormat(), $request->from(), $request->to());
        $table = new ExportTable('Journal', $file['headings'], [], $file['rows'], array_fill(0, count($file['headings']), 18));

        return new Response(SpreadsheetWriter::csv($table), 200, [
            'Content-Type' => SpreadsheetWriter::CSV_TYPE,
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
