<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SaveBayRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => $this->isMethod('POST') ? ['required', 'string', 'ulid'] : ['prohibited'],
            'name' => [$this->presence(), 'string', 'max:255'],
            'focus' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity_hours_per_day' => ['sometimes', 'numeric', 'decimal:0,3', 'gt:0', 'lte:24'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * Decimal hours travel as strings into the numeric(14,3) column, never floats.
     *
     * @return array<string, mixed>
     */
    public function bayAttributes(): array
    {
        $attributes = $this->validated();
        if (array_key_exists('capacity_hours_per_day', $attributes)) {
            $attributes['capacity_hours_per_day'] = $this->string('capacity_hours_per_day')->toString();
        }
        if (array_key_exists('branch_id', $attributes)) {
            $attributes['branch_id'] = $this->branchId();
        }

        return $attributes;
    }

    public function branchId(): string
    {
        return $this->string('branch_id')->lower()->toString();
    }
}
