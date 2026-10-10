<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

/** A customer's standing weekly slot. `day_of_week` is 0 (Sunday) to 6 (Saturday). */
final class RecurringScheduleRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'customer_account_id' => [$this->presence(), 'string', 'ulid'],
            'package_id' => ['required', 'string', 'ulid'],
            'size_class' => BookingRules::size(),
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => BookingRules::time(),
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    public function accountId(): ?string
    {
        return $this->filled('customer_account_id') ? Input::id($this->validated('customer_account_id')) : null;
    }

    /**
     * @return array{package_id: string, size_class: string, vehicle_id: string|null, day_of_week: int, start_minutes: int, is_active?: bool, notes: string|null}
     */
    public function schedule(): array
    {
        $data = [
            'package_id' => Input::id($this->validated('package_id')),
            'size_class' => Input::string($this->validated('size_class')),
            'vehicle_id' => $this->filled('vehicle_id') ? Input::id($this->validated('vehicle_id')) : null,
            'day_of_week' => Input::int($this->validated('day_of_week')),
            'start_minutes' => BookingRules::minutes($this->validated('start_time')),
            'notes' => Input::text($this->validated('notes')),
        ];
        if ($this->has('is_active')) {
            $data['is_active'] = $this->boolean('is_active');
        }

        return $data;
    }
}
