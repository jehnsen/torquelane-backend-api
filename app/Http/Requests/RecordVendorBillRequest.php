<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tax\Atc;

/**
 * A vendor's bill: who billed, which branch it is for, the vendor's own
 * invoice number and dates, how its prices treat VAT, what is withheld at
 * source, and its lines. Shape only: whether a receipt line can be billed, an
 * account charged or an invoice number reused is the action's to decide, and
 * every total is worked out there.
 */
final class RecordVendorBillRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'vendor_invoice_no' => ['required', 'string', 'max:64'],
            'bill_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'prices_include_vat' => ['sometimes', 'boolean'],
            'vat_rate_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,3'],
            // Absent: the vendor's usual code and rate. `false`: withhold nothing on this bill.
            'withholding' => ['sometimes', 'nullable'],
            'withholding.atc_code' => ['required_with:withholding.rate_pct', 'string', 'regex:'.Atc::PATTERN],
            'withholding.rate_pct' => ['required_with:withholding.atc_code', 'numeric', 'gt:0', 'max:100', 'decimal:0,3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.kind' => ['required', 'string', 'in:goods,expense'],
            'lines.*.goods_receipt_line_id' => ['required_if:lines.*.kind,goods', 'nullable', 'string', 'ulid'],
            'lines.*.account_id' => ['required_if:lines.*.kind,expense', 'nullable', 'string', 'ulid'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines.*.quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99999999', 'decimal:0,3'],
            'lines.*.unit_cost_cents' => ['required_if:lines.*.kind,expense', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.tax_class' => ['sometimes', 'nullable', 'string', 'in:vatable,vat_exempt,zero_rated'],
            // Paid on the spot: the cash-out voucher is recorded with the bill.
            'payment' => ['sometimes', 'nullable', 'array'],
            'payment.method' => ['required_with:payment', 'string', 'in:cash,gcash,maya,card,bank_transfer,check'],
            'payment.reference_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment.paid_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /** An ATC is written in capitals; `wc158` is taken as WC158. */
    protected function prepareForValidation(): void
    {
        $withholding = $this->input('withholding');
        if (is_array($withholding) && is_string($withholding['atc_code'] ?? null)) {
            $this->merge(['withholding' => ['atc_code' => strtoupper(trim($withholding['atc_code']))] + $withholding]);
        }
    }

    public function vendorId(): string
    {
        return Input::id($this->validated('vendor_id'));
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    /**
     * @return array{vendor_invoice_no: string, bill_date: string|null, due_date: string|null, prices_include_vat: bool|null, vat_rate_pct: string|null, withholding: array{atc_code: string, rate_pct: string}|false|null, notes: string|null, payment: array{method: string, reference_no: string|null, paid_on: string|null}|null, lines: list<array{kind: string, goods_receipt_line_id: string|null, account_id: string|null, description: string|null, quantity: string|null, unit_cost_cents: int|null, tax_class: string|null}>}
     */
    public function bill(): array
    {
        $lines = [];
        foreach (Input::rows($this->validated('lines')) as $row) {
            $lines[] = [
                'kind' => Input::string($row['kind'] ?? ''),
                'goods_receipt_line_id' => isset($row['goods_receipt_line_id']) ? Input::id($row['goods_receipt_line_id']) : null,
                'account_id' => isset($row['account_id']) ? Input::id($row['account_id']) : null,
                'description' => Input::text($row['description'] ?? null),
                'quantity' => Input::text($row['quantity'] ?? null),
                'unit_cost_cents' => isset($row['unit_cost_cents']) ? Input::int($row['unit_cost_cents']) : null,
                'tax_class' => Input::text($row['tax_class'] ?? null),
            ];
        }

        $payment = $this->validated('payment');

        return [
            'vendor_invoice_no' => trim(Input::string($this->validated('vendor_invoice_no'))),
            'bill_date' => Input::text($this->validated('bill_date')),
            'due_date' => Input::text($this->validated('due_date')),
            'prices_include_vat' => $this->has('prices_include_vat') ? $this->boolean('prices_include_vat') : null,
            'vat_rate_pct' => Input::text($this->validated('vat_rate_pct')),
            'withholding' => $this->withholding(),
            'notes' => Input::text($this->validated('notes')),
            'payment' => is_array($payment) ? [
                'method' => Input::string($payment['method'] ?? ''),
                'reference_no' => Input::text($payment['reference_no'] ?? null),
                'paid_on' => Input::text($payment['paid_on'] ?? null),
            ] : null,
            'lines' => $lines,
        ];
    }

    /**
     * @return array{atc_code: string, rate_pct: string}|false|null null = the vendor's default, false = none
     */
    private function withholding(): array|false|null
    {
        if (! $this->has('withholding')) {
            return null;
        }
        $value = $this->input('withholding');
        if (! is_array($value) || ! isset($value['atc_code'], $value['rate_pct'])) {
            return false;
        }

        return ['atc_code' => strtoupper(Input::string($value['atc_code'])), 'rate_pct' => Input::string($value['rate_pct'])];
    }
}
