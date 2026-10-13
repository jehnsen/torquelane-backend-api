<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Shared\Calendar;
use App\Domain\Tax\Quarter;
use Carbon\CarbonImmutable;

/**
 * Tax reads. A period is `from`..`to` (business dates), or a calendar
 * quarter (`year` + `quarter`), which most BIR returns cover; with neither,
 * the quarter today falls in. `file=csv` downloads a list as a spreadsheet.
 */
final class TaxReportRequest extends LedgerRangeRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2999', 'required_with:quarter'],
            'quarter' => ['sometimes', 'integer', 'min:1', 'max:4', 'required_with:year'],
            'file' => ['sometimes', 'string', 'in:json,csv'],
        ]);
    }

    public function quarter(): Quarter
    {
        return $this->filled('year') && $this->filled('quarter')
            ? new Quarter($this->integer('year'), $this->integer('quarter'))
            : Quarter::of(Calendar::toDate(CarbonImmutable::now()));
    }

    public function from(): string
    {
        return $this->filled('from') ? Input::string($this->input('from')) : $this->quarter()->startsOn();
    }

    public function to(): string
    {
        return $this->filled('to') ? Input::string($this->input('to')) : ($this->filled('from') ? Calendar::toDate(CarbonImmutable::now()) : $this->quarter()->endsOn());
    }

    public function wantsCsv(): bool
    {
        return $this->input('file') === 'csv';
    }
}
