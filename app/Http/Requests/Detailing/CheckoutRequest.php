<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

/** The counter's checkout: how the customer paid. `amount_cents` defaults to everything due. */
final class CheckoutRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', 'in:cash,gcash,maya,card,bank_transfer,check'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{method: string, reference_no: string|null, amount_cents: int|null, notes: string|null}
     */
    public function payment(): array
    {
        return [
            'method' => Input::string($this->validated('method')),
            'reference_no' => Input::text($this->validated('reference_no')),
            'amount_cents' => $this->filled('amount_cents') ? Input::int($this->validated('amount_cents')) : null,
            'notes' => Input::text($this->validated('notes')),
        ];
    }
}
