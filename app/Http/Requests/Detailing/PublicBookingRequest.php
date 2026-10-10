<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

/**
 * A booking request from a branch's public page. The customer says who they
 * are, what they drive, which package and size, and when; and ticks the
 * consent to keep their service records, without which no account is opened.
 * `captcha_token` is checked when the server has a captcha configured.
 */
final class PublicBookingRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'package_id' => ['required', 'string', 'ulid'],
            'size_class' => BookingRules::size(),
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => BookingRules::time(),
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:7', 'max:32', 'regex:/^[0-9+() -]+$/'],
            'vehicle_make' => ['required', 'string', 'max:100'],
            'vehicle_model' => ['required', 'string', 'max:100'],
            'vehicle_year' => ['sometimes', 'nullable', 'integer', 'between:1950,2100'],
            'plate_number' => ['sometimes', 'nullable', 'string', 'max:16'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'consent_service_records' => ['required', 'accepted'],
            'captcha_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }

    public function captchaToken(): ?string
    {
        return Input::text($this->validated('captcha_token'));
    }

    /**
     * @return array{package_id: string, size_class: string, date: string, start_minutes: int, first_name: string, last_name: string, email: string, phone: string, vehicle_make: string, vehicle_model: string, vehicle_year: int|null, plate_number: string|null, notes: string|null, ip: string|null}
     */
    public function booking(): array
    {
        return [
            'package_id' => Input::id($this->validated('package_id')),
            'size_class' => Input::string($this->validated('size_class')),
            'date' => Input::string($this->validated('date')),
            'start_minutes' => BookingRules::minutes($this->validated('start_time')),
            'first_name' => Input::string($this->validated('first_name')),
            'last_name' => Input::string($this->validated('last_name')),
            'email' => Input::string($this->validated('email')),
            'phone' => Input::string($this->validated('phone')),
            'vehicle_make' => Input::string($this->validated('vehicle_make')),
            'vehicle_model' => Input::string($this->validated('vehicle_model')),
            'vehicle_year' => $this->filled('vehicle_year') ? Input::int($this->validated('vehicle_year')) : null,
            'plate_number' => Input::text($this->validated('plate_number')),
            'notes' => Input::text($this->validated('notes')),
            'ip' => $this->ip(),
        ];
    }
}
