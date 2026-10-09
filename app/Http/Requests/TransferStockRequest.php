<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class TransferStockRequest extends ApiRequest
{
    private const string QUANTITY = '/^\d{1,11}(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'from_location_id' => ['required', 'string', 'ulid'],
            'to_location_id' => ['required', 'string', 'ulid', 'different:from_location_id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'string', 'ulid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:'.self::QUANTITY],
        ];
    }

    public function fromLocationId(): string
    {
        return $this->string('from_location_id')->lower()->toString();
    }

    public function toLocationId(): string
    {
        return $this->string('to_location_id')->lower()->toString();
    }

    public function notes(): string
    {
        return $this->filled('notes') ? trim($this->string('notes')->toString()) : '';
    }

    /**
     * @return list<array{item_id: string, quantity: string}>
     */
    public function lines(): array
    {
        $lines = [];
        foreach (Input::rows($this->input('lines')) as $input) {
            $lines[] = ['item_id' => Input::id($input['item_id'] ?? null), 'quantity' => Input::string($input['quantity'] ?? null)];
        }

        return $lines;
    }
}
