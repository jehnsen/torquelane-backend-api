<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A journal voucher: the branch it is filed under, its business date, a
 * reference and memo, and its lines (an account and a debit or a credit in
 * centavos; a line may sit in another of the caller's branches). Whether it
 * balances and whether an account may be posted to by hand is the action's.
 */
final class PostManualJournalRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'entry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'memo' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'string', 'ulid'],
            'lines.*.debit_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.credit_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    /**
     * @return array{entry_date: string|null, reference: string|null, memo: string, lines: list<array{account_id: string, debit_cents: int, credit_cents: int, branch_id: string|null, memo: string|null}>}
     */
    public function voucher(): array
    {
        $lines = [];
        foreach (Input::rows($this->validated('lines')) as $row) {
            $lines[] = [
                'account_id' => Input::id($row['account_id'] ?? ''),
                'debit_cents' => Input::int($row['debit_cents'] ?? 0),
                'credit_cents' => Input::int($row['credit_cents'] ?? 0),
                'branch_id' => isset($row['branch_id']) ? Input::id($row['branch_id']) : null,
                'memo' => Input::text($row['memo'] ?? null),
            ];
        }

        return [
            'entry_date' => Input::text($this->validated('entry_date')),
            'reference' => Input::text($this->validated('reference')),
            'memo' => trim(Input::string($this->validated('memo'))),
            'lines' => $lines,
        ];
    }
}
