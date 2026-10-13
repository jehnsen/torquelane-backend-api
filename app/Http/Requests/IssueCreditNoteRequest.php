<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A credit note against an invoice: why, when, and what is credited. A line
 * names an invoice line (with nothing else: what is left of that line; with a
 * quantity: that many at the line's price; with a price: that amount), or is
 * an allowance of its own (description and amount). The totals are worked out
 * by the action under the invoice's own VAT treatment.
 */
final class IssueCreditNoteRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.invoice_line_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines.*.quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99999999', 'decimal:0,3'],
            'lines.*.unit_price_cents' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'lines.*.tax_class' => ['sometimes', 'nullable', 'string', 'in:vatable,vat_exempt,zero_rated'],
        ];
    }

    /**
     * @return array{reason: string, issued_on: string|null, lines: list<array{invoice_line_id: string|null, description: string|null, quantity: string|null, unit_price_cents: int|null, tax_class: string|null}>}
     */
    public function creditNote(): array
    {
        $lines = [];
        foreach (Input::rows($this->validated('lines')) as $row) {
            $lines[] = [
                'invoice_line_id' => isset($row['invoice_line_id']) ? Input::id($row['invoice_line_id']) : null,
                'description' => Input::text($row['description'] ?? null),
                'quantity' => Input::text($row['quantity'] ?? null),
                'unit_price_cents' => isset($row['unit_price_cents']) ? Input::int($row['unit_price_cents']) : null,
                'tax_class' => Input::text($row['tax_class'] ?? null),
            ];
        }

        return [
            'reason' => trim(Input::string($this->validated('reason'))),
            'issued_on' => Input::text($this->validated('issued_on')),
            'lines' => $lines,
        ];
    }
}
