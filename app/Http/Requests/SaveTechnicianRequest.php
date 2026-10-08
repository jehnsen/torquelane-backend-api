<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SaveTechnicianRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => $this->isMethod('POST') ? ['required', 'string', 'ulid'] : ['prohibited'],
            'name' => [$this->presence(), 'string', 'max:255'],
            'skill_tags' => ['sometimes', 'array', 'max:20'],
            'skill_tags.*' => ['string', 'max:32', 'distinct', 'regex:/^[a-z][a-z0-9_]*$/'],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:255'],
            'home_bay_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'user_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function technicianAttributes(): array
    {
        $attributes = $this->validated();
        foreach (['branch_id', 'home_bay_id', 'user_id'] as $id) {
            if (is_string($attributes[$id] ?? null)) {
                $attributes[$id] = mb_strtolower($attributes[$id]);
            }
        }

        return $attributes;
    }

    public function branchId(): string
    {
        return $this->string('branch_id')->lower()->toString();
    }
}
