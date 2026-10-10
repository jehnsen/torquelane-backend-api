<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

/** A booking's bay, notes, contact details and vehicle: only what is sent changes. */
final class UpdateBookingRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'bay_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'vehicle_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array{notes?: string|null, bay_id?: string|null, customer_name?: string|null, customer_email?: string|null, customer_phone?: string|null, vehicle_id?: string|null, vehicle_label?: string|null}
     */
    public function changes(): array
    {
        $changes = [];
        foreach (['bay_id', 'vehicle_id'] as $field) {
            if ($this->has($field)) {
                $changes[$field] = $this->filled($field) ? Input::id($this->input($field)) : null;
            }
        }
        foreach (['vehicle_label', 'notes', 'customer_name', 'customer_email', 'customer_phone'] as $field) {
            if ($this->has($field)) {
                $changes[$field] = Input::text($this->input($field));
            }
        }

        return $changes;
    }
}
