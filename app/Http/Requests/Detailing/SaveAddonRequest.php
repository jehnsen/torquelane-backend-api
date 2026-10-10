<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\SizeClass;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use Illuminate\Validation\Rule;

final class SaveAddonRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'prices' => ['present', 'array', 'max:4'],
            'prices.*.size_class' => ['required', 'string', Rule::enum(SizeClass::class)],
            'prices.*.price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    /**
     * @return array{name: string, is_active?: bool, position?: int|null, prices: list<array{size_class: string, price_cents: int}>}
     */
    public function addon(): array
    {
        $data = [
            'name' => Input::string($this->validated('name')),
            'prices' => array_map(fn (array $row): array => [
                'size_class' => Input::string($row['size_class'] ?? ''),
                'price_cents' => Input::int($row['price_cents'] ?? 0),
            ], Input::rows($this->validated('prices'))),
        ];
        if ($this->has('is_active')) {
            $data['is_active'] = $this->boolean('is_active');
        }
        if ($this->filled('position')) {
            $data['position'] = Input::int($this->validated('position'));
        }

        return $data;
    }
}
