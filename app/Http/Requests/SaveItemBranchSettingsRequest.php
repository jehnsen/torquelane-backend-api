<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SaveItemBranchSettingsRequest extends ApiRequest
{
    private const string QUANTITY = '/^\d{1,11}(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reorder_point' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:'.self::QUANTITY],
            'reorder_qty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:'.self::QUANTITY],
            'bin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'price_override_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999999999'],
        ];
    }

    /**
     * @return array{reorder_point?: string|null, reorder_qty?: string|null, bin?: string|null, price_override_cents?: int|null}
     */
    public function settings(): array
    {
        $settings = [];
        foreach (['reorder_point', 'reorder_qty'] as $field) {
            if ($this->has($field)) {
                $settings[$field] = Input::text($this->input($field));
            }
        }
        if ($this->has('bin')) {
            $bin = $this->input('bin');
            $settings['bin'] = is_string($bin) && trim($bin) !== '' ? trim($bin) : null;
        }
        if ($this->has('price_override_cents')) {
            $price = $this->input('price_override_cents');
            $settings['price_override_cents'] = is_numeric($price) ? (int) $price : null;
        }

        return $settings;
    }
}
