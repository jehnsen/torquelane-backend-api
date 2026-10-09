<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class OpenStockCountRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'string', 'ulid'],
            'item_ids' => ['sometimes', 'array', 'max:500'],
            'item_ids.*' => ['string', 'ulid'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function locationId(): string
    {
        return $this->string('location_id')->lower()->toString();
    }

    /**
     * @return array{item_ids?: list<string>, reason?: string|null, notes?: string|null}
     */
    public function sheet(): array
    {
        $sheet = [];
        if ($this->has('item_ids')) {
            $ids = $this->input('item_ids');
            $sheet['item_ids'] = is_array($ids) ? array_values(array_map(Input::id(...), $ids)) : [];
        }
        foreach (['reason', 'notes'] as $field) {
            if ($this->filled($field)) {
                $sheet[$field] = trim($this->string($field)->toString());
            }
        }

        return $sheet;
    }
}
