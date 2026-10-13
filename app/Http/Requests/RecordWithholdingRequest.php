<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tax\Atc;

/**
 * A BIR 2307 received from a customer: the ATC and rate they withheld at, the
 * tax base if it is less than the invoice's sales before VAT, the
 * certificate's own number and period, and the day it was taken off the
 * invoice. The amount is worked out by the action.
 */
final class RecordWithholdingRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'atc_code' => ['required', 'string', 'regex:'.Atc::PATTERN],
            'rate_pct' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,3'],
            'tax_base_cents' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'certificate_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'received_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'required_with:period_to'],
            'period_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'required_with:period_from'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** An ATC is written in capitals; `wc160` is taken as WC160. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('atc_code'))) {
            $this->merge(['atc_code' => strtoupper(trim($this->input('atc_code')))]);
        }
    }

    /**
     * @return array{atc_code: string, rate_pct: string, tax_base_cents: int|null, certificate_no: string|null, received_on: string|null, period_from: string|null, period_to: string|null, notes: string|null}
     */
    public function certificate(): array
    {
        return [
            'atc_code' => strtoupper(Input::string($this->validated('atc_code'))),
            'rate_pct' => Input::string($this->validated('rate_pct')),
            'tax_base_cents' => $this->filled('tax_base_cents') ? Input::int($this->validated('tax_base_cents')) : null,
            'certificate_no' => Input::text($this->validated('certificate_no')),
            'received_on' => Input::text($this->validated('received_on')),
            'period_from' => Input::text($this->validated('period_from')),
            'period_to' => Input::text($this->validated('period_to')),
            'notes' => Input::text($this->validated('notes')),
        ];
    }
}
