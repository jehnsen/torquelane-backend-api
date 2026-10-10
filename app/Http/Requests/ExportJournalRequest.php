<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Ledger\LedgerExport;
use Illuminate\Validation\Rule;

/** `format` csv (always), xero or quickbooks (when that is the organization's accounting target); a date range. */
final class ExportJournalRequest extends LedgerRangeRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), ['format' => ['sometimes', 'string', Rule::in(LedgerExport::FORMATS)]]);
    }

    public function exportFormat(): string
    {
        return $this->filled('format') ? Input::string($this->input('format')) : 'csv';
    }
}
