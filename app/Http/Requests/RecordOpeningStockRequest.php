<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class RecordOpeningStockRequest extends ApiRequest
{
    private const string QUANTITY = '/^\d{1,11}(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'string', 'ulid'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'string', 'ulid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:'.self::QUANTITY],
            'lines.*.unit_cost_cents' => ['required', 'integer', 'min:0', 'max:9999999999'],
        ];
    }

    public function locationId(): string
    {
        return $this->string('location_id')->lower()->toString();
    }

    public function reason(): string
    {
        return $this->filled('reason') ? trim($this->string('reason')->toString()) : 'Opening balance';
    }

    /**
     * @return list<array{item_id: string, quantity: string, unit_cost_cents: int}>
     */
    public function lines(): array
    {
        $lines = [];
        foreach (Input::rows($this->input('lines')) as $input) {
            $lines[] = [
                'item_id' => Input::id($input['item_id'] ?? null),
                'quantity' => Input::string($input['quantity'] ?? null),
                'unit_cost_cents' => Input::int($input['unit_cost_cents'] ?? null),
            ];
        }

        return $lines;
    }
}
