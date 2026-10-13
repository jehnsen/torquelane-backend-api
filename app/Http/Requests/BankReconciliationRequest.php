<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Bank reconciliation writes. Opening one: the account, the statement's
 * closing date and its closing balance in centavos (negative when overdrawn).
 * Ticking: the journal lines the statement shows (`clear`) and any ticked by
 * mistake (`unclear`).
 */
final class BankReconciliationRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if ($this->isMethod('PUT')) {
            return [
                'clear' => ['sometimes', 'array', 'max:2000'],
                'clear.*' => ['string', 'ulid'],
                'unclear' => ['sometimes', 'array', 'max:2000'],
                'unclear.*' => ['string', 'ulid'],
            ];
        }

        return [
            'account_id' => ['required', 'string', 'ulid'],
            'statement_date' => ['required', 'date_format:Y-m-d'],
            'statement_balance_cents' => ['required', 'integer', 'min:-100000000000000', 'max:100000000000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function accountId(): string
    {
        return Input::id($this->validated('account_id'));
    }

    /**
     * @return array{statement_date: string, statement_balance_cents: int, notes: string|null}
     */
    public function statement(): array
    {
        return [
            'statement_date' => Input::string($this->validated('statement_date')),
            'statement_balance_cents' => Input::int($this->validated('statement_balance_cents')),
            'notes' => Input::text($this->validated('notes')),
        ];
    }

    /**
     * @return list<string>
     */
    public function clear(): array
    {
        return $this->ids('clear');
    }

    /**
     * @return list<string>
     */
    public function unclear(): array
    {
        return $this->ids('unclear');
    }

    /**
     * @return list<string>
     */
    private function ids(string $key): array
    {
        $ids = [];
        $values = $this->validated($key);
        foreach (is_array($values) ? $values : [] as $value) {
            $ids[] = Input::id($value);
        }

        return $ids;
    }
}
