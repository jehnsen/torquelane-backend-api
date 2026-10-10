<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\SizeClass;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use App\Models\DetailingPackage;
use Illuminate\Validation\Rule;

/** A package with its price per size class and, optionally, its bill of materials (the whole list). */
final class SavePackageRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'duration_text' => ['required', 'string', 'max:64'],
            'inclusions' => ['sometimes', 'array', 'max:60'],
            'inclusions.*' => ['string', 'max:255'],
            'is_popular' => ['sometimes', 'boolean'],
            'min_detailers' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'recommended_detailers' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'applies_to' => ['sometimes', 'string', Rule::in(DetailingPackage::APPLIES_TO)],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'prices' => ['required', 'array', 'min:1', 'max:4'],
            'prices.*.size_class' => ['required', 'string', Rule::enum(SizeClass::class)],
            'prices.*.price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'prices.*.duration_text' => ['sometimes', 'nullable', 'string', 'max:64'],
            'materials' => ['sometimes', 'nullable', 'array', 'max:60'],
            'materials.*.item_id' => ['required', 'string', 'ulid'],
            'materials.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'materials.*.scales_with_size' => ['sometimes', 'boolean'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    /**
     * @return array{name: string, description?: string|null, category_id?: string|null, duration_text: string, inclusions?: list<string>, is_popular?: bool, min_detailers?: int, recommended_detailers?: int, applies_to?: string, is_active?: bool, position?: int|null, prices: list<array{size_class: string, price_cents: int, duration_text?: string|null}>, materials?: list<array{item_id: string, quantity: string, scales_with_size?: bool}>|null}
     */
    public function package(): array
    {
        $data = [
            'name' => Input::string($this->validated('name')),
            'description' => Input::text($this->validated('description')),
            'category_id' => $this->filled('category_id') ? Input::id($this->validated('category_id')) : null,
            'duration_text' => Input::string($this->validated('duration_text')),
            'prices' => array_map(fn (array $row): array => [
                'size_class' => Input::string($row['size_class'] ?? ''),
                'price_cents' => Input::int($row['price_cents'] ?? 0),
                'duration_text' => Input::text($row['duration_text'] ?? null),
            ], Input::rows($this->validated('prices'))),
        ];
        if ($this->has('inclusions')) {
            $lines = $this->validated('inclusions');
            $data['inclusions'] = is_array($lines) ? array_values(array_map(Input::string(...), $lines)) : [];
        }
        foreach (['is_popular', 'is_active'] as $flag) {
            if ($this->has($flag)) {
                $data[$flag] = $this->boolean($flag);
            }
        }
        foreach (['min_detailers', 'recommended_detailers', 'position'] as $number) {
            if ($this->filled($number)) {
                $data[$number] = Input::int($this->validated($number));
            }
        }
        if ($this->filled('applies_to')) {
            $data['applies_to'] = Input::string($this->validated('applies_to'));
        }
        if ($this->has('materials')) {
            $data['materials'] = array_map(fn (array $row): array => [
                'item_id' => Input::id($row['item_id'] ?? ''),
                'quantity' => Input::string($row['quantity'] ?? '0'),
                'scales_with_size' => ! array_key_exists('scales_with_size', $row) || filter_var($row['scales_with_size'], FILTER_VALIDATE_BOOLEAN),
            ], Input::rows($this->validated('materials')));
        }

        return $data;
    }
}
