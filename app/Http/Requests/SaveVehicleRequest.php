<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST registers a vehicle with its first odometer reading (and any known
 * service history); PATCH edits details. The odometer never changes here
 * (POST …/readings), nor the owner (POST …/transfer).
 */
final class SaveVehicleRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $date = ['sometimes', 'nullable', 'date_format:Y-m-d'];

        $rules = [
            // Portal users register vehicles to their own account; staff name one.
            'customer_account_id' => $creating ? [($this->tenant()?->isStaff() ?? false) ? 'required' : 'sometimes', 'string', 'ulid'] : ['prohibited'],
            'plate_number' => [$this->presence(), 'string', 'max:32'],
            'make' => ['sometimes', 'nullable', 'string', 'max:64'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.((int) date('Y') + 1)],
            'vin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'vehicle_class' => ['sometimes', 'nullable', 'string', Rule::in(['sedan', 'suv', 'pickup', 'van', 'truck'])],
            'fuel_type' => ['sometimes', 'nullable', 'string', Rule::in(['gasoline', 'diesel', 'hybrid', 'electric'])],
            'size_class' => ['sometimes', 'nullable', 'string', Rule::in(['small', 'medium', 'large', 'xl'])],
            'color' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'in_service', 'down'])],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'acquired_on' => [...$date, 'before_or_equal:today'],
            'registration_expiry' => $date,
            'insurance_expiry' => $date,
            'driver_licence_expiry' => $date,
        ];

        if ($creating) {
            return $rules + [
                'odometer' => ['required', 'array:value,read_on'],
                'odometer.value' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
                'odometer.read_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'service_history' => ['sometimes', 'array', 'max:100'],
                'service_history.*.service_task_id' => ['required', 'string', 'ulid', 'distinct'],
                'service_history.*.last_done_value' => ['required', 'numeric', 'decimal:0,3', 'min:0'],
                'service_history.*.last_done_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            ];
        }

        return $rules + ['odometer' => ['prohibited'], 'service_history' => ['prohibited']];
    }

    /**
     * The vehicle's own columns.
     *
     * @return array<string, mixed>
     */
    public function vehicleAttributes(): array
    {
        return array_diff_key($this->validated(), array_flip(['customer_account_id', 'odometer', 'service_history']));
    }

    /**
     * @return array{value: string, read_on: string|null}
     */
    public function odometer(): array
    {
        return [
            'value' => $this->string('odometer.value')->toString(),
            'read_on' => $this->filled('odometer.read_on') ? $this->string('odometer.read_on')->toString() : null,
        ];
    }

    /**
     * @return list<array{service_task_id: string, last_done_value: string, last_done_on: string}>
     */
    public function serviceHistory(): array
    {
        $history = [];
        foreach ($this->array('service_history') as $entry) {
            if (is_array($entry)) {
                $history[] = [
                    'service_task_id' => mb_strtolower(is_string($entry['service_task_id'] ?? null) ? $entry['service_task_id'] : ''),
                    'last_done_value' => is_scalar($entry['last_done_value'] ?? null) ? (string) $entry['last_done_value'] : '0',
                    'last_done_on' => is_string($entry['last_done_on'] ?? null) ? $entry['last_done_on'] : '',
                ];
            }
        }

        return $history;
    }
}
