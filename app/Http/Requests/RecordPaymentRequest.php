<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A payment received: the account, the branch receiving it, the method (a
 * reference number unless cash), the amount in centavos, the business date it
 * came in, and optionally how to spread it (else oldest due first).
 */
final class RecordPaymentRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_account_id' => ['required', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'method' => ['required', 'string', 'in:cash,gcash,maya,card,bank_transfer,check'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'received_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...AllocatePaymentRequest::allocationRules(),
        ];
    }

    public function accountId(): string
    {
        return Input::id($this->validated('customer_account_id'));
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    /**
     * @return array{method: string, reference_no: string|null, amount_cents: int, received_on: string|null, notes: string|null, allocations: list<array{invoice_id: string, amount_cents: int}>|null}
     */
    public function payment(): array
    {
        return [
            'method' => Input::string($this->validated('method')),
            'reference_no' => Input::text($this->validated('reference_no')),
            'amount_cents' => Input::int($this->validated('amount_cents')),
            'received_on' => Input::text($this->validated('received_on')),
            'notes' => Input::text($this->validated('notes')),
            'allocations' => AllocatePaymentRequest::read($this),
        ];
    }
}
