<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SaveShopOrderRequest extends ApiRequest
{
    private const string QUANTITY = '/^\d{1,11}(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'branch_id' => $create ? ['required', 'string', 'ulid'] : ['prohibited'],
            'vendor_id' => [$this->presence(), 'string', 'ulid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expected_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'lines' => [$this->presence(), 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['nullable', 'string', 'ulid'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:'.self::QUANTITY],
            'lines.*.unit_cost_cents' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'lines.*.work_order_line_id' => ['nullable', 'string', 'ulid'],
        ];
    }

    public function branchId(): string
    {
        return $this->string('branch_id')->lower()->toString();
    }

    /**
     * @return array{branch_id?: string, vendor_id?: string, notes?: string, expected_on?: string|null, lines?: list<array<string, mixed>>}
     */
    public function order(): array
    {
        $order = [];
        if ($this->has('branch_id')) {
            $order['branch_id'] = $this->branchId();
        }
        if ($this->has('vendor_id')) {
            $order['vendor_id'] = $this->string('vendor_id')->lower()->toString();
        }
        if ($this->has('notes')) {
            $order['notes'] = trim($this->string('notes')->toString());
        }
        if ($this->has('expected_on')) {
            $expected = $this->input('expected_on');
            $order['expected_on'] = is_string($expected) && $expected !== '' ? $expected : null;
        }
        if ($this->has('lines')) {
            $lines = [];
            foreach (Input::rows($this->input('lines')) as $input) {
                $line = ['quantity' => Input::string($input['quantity'] ?? '0'), 'unit_cost_cents' => Input::int($input['unit_cost_cents'] ?? 0)];
                foreach (['item_id', 'work_order_line_id'] as $id) {
                    if (isset($input[$id]) && is_string($input[$id]) && $input[$id] !== '') {
                        $line[$id] = strtolower($input[$id]);
                    }
                }
                if (isset($input['description']) && is_string($input['description'])) {
                    $line['description'] = $input['description'];
                }
                $lines[] = $line;
            }
            $order['lines'] = $lines;
        }

        return $order;
    }
}
