<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Validation\Validator;

/**
 * Raising a draft (POST): `work_order_ids` (closed jobs of one account), or
 * a typed-in invoice (`customer_account_id`, `branch_id`, `lines`). Editing a
 * draft (PATCH): `notes`, a typed-in invoice's `lines`, and `discounts` on any.
 * Lines carry quantities and prices; totals are the server's.
 */
final class SaveInvoiceRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'work_order_ids' => $creating ? ['required_without:lines', 'prohibits:lines', 'array', 'min:1', 'max:50'] : ['prohibited'],
            'work_order_ids.*' => ['string', 'ulid', 'distinct'],
            'customer_account_id' => $creating ? ['required_with:lines', 'string', 'ulid'] : ['prohibited'],
            'branch_id' => $creating ? ['sometimes', 'string', 'ulid'] : ['prohibited'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'min:1', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', 'decimal:0,3'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.discount_cents' => ['sometimes', 'integer', 'min:0'],
            'lines.*.tax_class' => ['sometimes', 'string', 'in:vatable,vat_exempt,zero_rated'],
            'lines.*.item_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'lines.*.service_task_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'discounts' => $creating ? ['prohibited'] : ['sometimes', 'array', 'max:200'],
            'discounts.*.line_id' => ['required', 'string', 'ulid'],
            'discounts.*.discount_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isMethod('POST') && $this->has('work_order_ids')) {
                    $validator->errors()->add('work_order_ids', 'The jobs on a draft are fixed; discard it and raise another.');
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    public function workOrderIds(): array
    {
        return array_values(array_map(Input::id(...), is_array($this->validated('work_order_ids')) ? $this->validated('work_order_ids') : []));
    }

    public function accountId(): string
    {
        return Input::id($this->validated('customer_account_id'));
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    public function notes(): string
    {
        return trim(Input::string($this->validated('notes', '')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        return array_map(fn (array $row): array => [
            'description' => Input::string($row['description'] ?? ''),
            'quantity' => Input::string($row['quantity'] ?? '0'),
            'unit_price_cents' => Input::int($row['unit_price_cents'] ?? 0),
            'discount_cents' => Input::int($row['discount_cents'] ?? 0),
            'tax_class' => Input::string($row['tax_class'] ?? 'vatable'),
            'item_id' => isset($row['item_id']) ? Input::id($row['item_id']) : null,
            'service_task_id' => isset($row['service_task_id']) ? Input::id($row['service_task_id']) : null,
        ], Input::rows($this->validated('lines', [])));
    }

    /**
     * @return array{notes?: string|null, lines?: list<array<string, mixed>>, discounts?: list<array{line_id: string, discount_cents: int}>}
     */
    public function draftChanges(): array
    {
        $changes = [];
        if ($this->has('notes')) {
            $changes['notes'] = $this->notes();
        }
        if ($this->has('lines')) {
            $changes['lines'] = $this->lines();
        }
        if ($this->has('discounts')) {
            $changes['discounts'] = array_map(fn (array $row): array => [
                'line_id' => Input::id($row['line_id'] ?? ''),
                'discount_cents' => Input::int($row['discount_cents'] ?? 0),
            ], Input::rows($this->validated('discounts', [])));
        }

        return $changes;
    }
}
