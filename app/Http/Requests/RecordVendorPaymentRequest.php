<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A payment to a vendor: the vendor, the branch paying, the method (a
 * reference number unless cash), the business date, and either the bills it
 * settles with their amounts or one amount to spread oldest due first.
 */
final class RecordVendorPaymentRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'method' => ['required', 'string', 'in:cash,gcash,maya,card,bank_transfer,check'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'amount_cents' => ['required_without:allocations', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'paid_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'allocations' => ['sometimes', 'nullable', 'array', 'min:1', 'max:100'],
            'allocations.*.vendor_bill_id' => ['required', 'string', 'ulid'],
            'allocations.*.amount_cents' => ['required', 'integer', 'min:1'],
        ];
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
     * @return array{method: string, reference_no: string|null, amount_cents: int|null, paid_on: string|null, notes: string|null, allocations: list<array{vendor_bill_id: string, amount_cents: int}>|null}
     */
    public function payment(): array
    {
        $rows = $this->validated('allocations');

        return [
            'method' => Input::string($this->validated('method')),
            'reference_no' => Input::text($this->validated('reference_no')),
            'amount_cents' => $this->filled('amount_cents') ? Input::int($this->validated('amount_cents')) : null,
            'paid_on' => Input::text($this->validated('paid_on')),
            'notes' => Input::text($this->validated('notes')),
            'allocations' => is_array($rows) ? array_map(fn (array $row): array => [
                'vendor_bill_id' => Input::id($row['vendor_bill_id'] ?? ''),
                'amount_cents' => Input::int($row['amount_cents'] ?? 0),
            ], Input::rows($rows)) : null,
        ];
    }
}
