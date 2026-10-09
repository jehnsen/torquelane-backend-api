<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Issuing: optionally an earlier business date (never in the future, never behind the series). */
final class IssueInvoiceRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['issue_date' => ['sometimes', 'nullable', 'date_format:Y-m-d']];
    }

    public function issueDate(): ?string
    {
        return $this->filled('issue_date') ? Input::string($this->validated('issue_date')) : null;
    }
}
