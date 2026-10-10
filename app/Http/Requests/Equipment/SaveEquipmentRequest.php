<?php

declare(strict_types=1);

namespace App\Http\Requests\Equipment;

use App\Domain\Equipment\EquipmentVocabulary;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use Illuminate\Validation\Rule;

/**
 * A tool of the register. On an edit only what is sent changes; a nullable
 * field sent as null is cleared. Hours are decimals to three places.
 */
final class SaveEquipmentRequest extends ApiRequest
{
    private const string DECIMAL = 'regex:/^\d+(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'name' => [$this->presence(), 'string', 'max:255'],
            'category' => ['sometimes', 'string', Rule::in(array_keys(EquipmentVocabulary::CATEGORIES))],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bay_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'status' => ['sometimes', 'string', Rule::in(array_keys(EquipmentVocabulary::STATUSES))],
            'condition_rating' => ['sometimes', 'string', Rule::in(array_keys(EquipmentVocabulary::CONDITIONS))],
            'purchase_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'purchase_cost_cents' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'warranty_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expected_life_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:10000000', self::DECIMAL],
            'meter_type' => ['sometimes', 'string', Rule::in(array_keys(EquipmentVocabulary::METER_TYPES))],
            'baseline_hours' => ['sometimes', 'numeric', 'min:0', 'max:10000000', self::DECIMAL],
            'baseline_cycles' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'service_interval_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:10000000', self::DECIMAL],
            'service_interval_cycles' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'service_interval_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:36500'],
            'maintenance_task' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function tool(): array
    {
        $data = [];
        foreach (['name', 'category', 'status', 'condition_rating', 'meter_type'] as $field) {
            if ($this->filled($field)) {
                $data[$field] = Input::string($this->validated($field));
            }
        }
        foreach (['brand', 'model', 'serial_number', 'purchase_date', 'warranty_until', 'maintenance_task', 'notes'] as $field) {
            if ($this->has($field)) {
                $data[$field] = Input::text($this->input($field));
            }
        }
        foreach (['expected_life_hours', 'service_interval_hours'] as $field) {
            if ($this->has($field)) {
                $data[$field] = $this->filled($field) ? Input::string($this->validated($field)) : null;
            }
        }
        if ($this->filled('baseline_hours')) {
            $data['baseline_hours'] = Input::string($this->validated('baseline_hours'));
        }
        foreach (['service_interval_cycles', 'service_interval_days'] as $field) {
            if ($this->has($field)) {
                $data[$field] = $this->filled($field) ? Input::int($this->validated($field)) : null;
            }
        }
        foreach (['purchase_cost_cents', 'baseline_cycles', 'position'] as $field) {
            if ($this->filled($field)) {
                $data[$field] = Input::int($this->validated($field));
            }
        }
        if ($this->has('bay_id')) {
            $data['bay_id'] = $this->filled('bay_id') ? Input::id($this->validated('bay_id')) : null;
        }
        if ($this->has('is_active')) {
            $data['is_active'] = $this->boolean('is_active');
        }

        return $data;
    }
}
