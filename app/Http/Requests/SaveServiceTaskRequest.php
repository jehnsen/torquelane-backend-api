<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ServiceTask;
use Illuminate\Validation\Rule;

final class SaveServiceTaskRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => [$this->presence(), 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => [$this->presence(), 'string', 'max:255'],
            'category' => [$this->presence(), 'string', Rule::in(ServiceTask::CATEGORIES)],
            'interval_km' => [$this->presence(), 'integer', 'min:1', 'max:1000000'],
            'interval_months' => [$this->presence(), 'integer', 'min:1', 'max:240'],
            'estimated_cost_cents' => ['sometimes', 'integer', 'min:0'],
            'estimated_hours' => ['sometimes', 'numeric', 'decimal:0,3', 'min:0', 'max:999'],
            'critical' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function taskAttributes(): array
    {
        $attributes = $this->validated();
        if (array_key_exists('estimated_hours', $attributes)) {
            $attributes['estimated_hours'] = $this->string('estimated_hours')->toString();
        }

        return $attributes;
    }
}
