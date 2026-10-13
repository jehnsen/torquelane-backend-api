<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A refund: the one source it pays out of (a payment's unallocated money, or
 * a credit note's credit), the method (a reference number unless cash), the
 * amount (default: everything the source still holds), the business date and
 * the reason.
 */
final class PayRefundRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_id' => ['required_without:credit_note_id', 'prohibits:credit_note_id', 'nullable', 'string', 'ulid'],
            'credit_note_id' => ['required_without:payment_id', 'nullable', 'string', 'ulid'],
            'method' => ['required', 'string', 'in:cash,gcash,maya,card,bank_transfer,check'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'refunded_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function paymentId(): ?string
    {
        return $this->filled('payment_id') ? Input::id($this->validated('payment_id')) : null;
    }

    public function creditNoteId(): ?string
    {
        return $this->filled('credit_note_id') ? Input::id($this->validated('credit_note_id')) : null;
    }

    /**
     * @return array{method: string, reference_no: string|null, amount_cents: int|null, refunded_on: string|null, reason: string}
     */
    public function refund(): array
    {
        return [
            'method' => Input::string($this->validated('method')),
            'reference_no' => Input::text($this->validated('reference_no')),
            'amount_cents' => $this->filled('amount_cents') ? Input::int($this->validated('amount_cents')) : null,
            'refunded_on' => Input::text($this->validated('refunded_on')),
            'reason' => trim(Input::string($this->validated('reason'))),
        ];
    }
}
