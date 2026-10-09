<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class ReceiveGoodsRequest extends ApiRequest
{
    private const string QUANTITY = '/^\d{1,11}(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'supplier_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.shop_purchase_order_line_id' => ['required', 'string', 'ulid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:'.self::QUANTITY],
            'lines.*.unit_cost_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999999999'],
        ];
    }

    /**
     * @return array{lines: list<array{shop_purchase_order_line_id: string, quantity: string, unit_cost_cents?: int|null}>, supplier_ref?: string|null, notes?: string|null}
     */
    public function receipt(): array
    {
        $lines = [];
        foreach (Input::rows($this->input('lines')) as $input) {
            $line = [
                'shop_purchase_order_line_id' => Input::id($input['shop_purchase_order_line_id'] ?? null),
                'quantity' => Input::string($input['quantity'] ?? null),
            ];
            if (isset($input['unit_cost_cents'])) {
                $line['unit_cost_cents'] = Input::int($input['unit_cost_cents']);
            }
            $lines[] = $line;
        }

        $receipt = ['lines' => $lines];
        if ($this->filled('supplier_ref')) {
            $receipt['supplier_ref'] = trim($this->string('supplier_ref')->toString());
        }
        if ($this->filled('notes')) {
            $receipt['notes'] = trim($this->string('notes')->toString());
        }

        return $receipt;
    }
}
