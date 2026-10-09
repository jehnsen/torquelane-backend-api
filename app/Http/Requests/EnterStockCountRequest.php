<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class EnterStockCountRequest extends ApiRequest
{
    private const string QUANTITY = '/^\d{1,11}(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'string', 'ulid'],
            // null un-counts a line.
            'lines.*.counted_quantity' => ['present', 'nullable', 'numeric', 'min:0', 'regex:'.self::QUANTITY],
            'lines.*.reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return list<array{item_id: string, counted_quantity: string|null, reason?: string|null}>
     */
    public function lines(): array
    {
        $lines = [];
        foreach (Input::rows($this->input('lines')) as $input) {
            $line = [
                'item_id' => Input::id($input['item_id'] ?? null),
                'counted_quantity' => Input::text($input['counted_quantity'] ?? null),
            ];
            if (array_key_exists('reason', $input)) {
                $line['reason'] = Input::text($input['reason']);
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
