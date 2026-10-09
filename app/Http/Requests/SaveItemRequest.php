<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Inventory\SaveItem;
use App\Domain\Inventory\ItemType;
use App\Domain\Inventory\TaxClass;
use Illuminate\Validation\Rule;

final class SaveItemRequest extends ApiRequest
{
    /** At most three decimals, positive. */
    private const string FACTOR = '/^\d{1,11}(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $presence = $this->presence();

        return [
            'sku' => [$presence, 'string', 'min:1', 'max:64'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'name' => [$presence, 'string', 'min:1', 'max:255'],
            'item_type' => [$presence, Rule::enum(ItemType::class)],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'uom' => [$presence, 'string', 'min:1', 'max:16'],
            'purchase_uom' => ['sometimes', 'nullable', 'string', 'max:16'],
            'purchase_uom_factor' => ['sometimes', 'numeric', 'gt:0', 'regex:'.self::FACTOR],
            'tax_class' => ['sometimes', Rule::enum(TaxClass::class)],
            'default_price_cents' => ['sometimes', 'integer', 'min:0', 'max:9999999999'],
            'is_stocked' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'preferred_vendor_id' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

    /**
     * Only what was sent (an unsent field is left alone, an empty optional text clears it).
     *
     * @return array<string, mixed>
     */
    public function itemAttributes(): array
    {
        $attributes = [];
        foreach (SaveItem::FIELDS as $field) {
            if (! $this->has($field)) {
                continue;
            }
            $value = $this->input($field);
            $attributes[$field] = match ($field) {
                'is_stocked', 'is_active' => $this->boolean($field),
                'purchase_uom_factor' => Input::string($value),
                'default_price_cents' => Input::int($value),
                'preferred_vendor_id' => is_string($value) ? strtolower($value) : null,
                'category' => is_string($value) ? $value : '',
                default => $value,
            };
        }

        return $attributes;
    }
}
