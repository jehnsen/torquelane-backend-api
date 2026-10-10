<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\SizeClass;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

/**
 * Take a booking for one of the shop's customers. The plan, the price and the
 * heads are the API's: the request says who, what, which size, and when.
 */
final class TakeBookingRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'customer_account_id' => ['required', 'string', 'ulid'],
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'vehicle_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'package_id' => ['required', 'string', 'ulid'],
            'size_class' => BookingRules::size(),
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => BookingRules::time(),
            'detailers_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'detailer_ids' => ['sometimes', 'array', 'max:20'],
            'detailer_ids.*' => ['string', 'ulid'],
            'bay_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'status' => ['sometimes', 'string', 'in:pending,confirmed'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            ...BookingRules::addons(),
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    public function accountId(): string
    {
        return Input::id($this->validated('customer_account_id'));
    }

    public function vehicleId(): ?string
    {
        return $this->filled('vehicle_id') ? Input::id($this->validated('vehicle_id')) : null;
    }

    public function packageId(): string
    {
        return Input::id($this->validated('package_id'));
    }

    public function size(): SizeClass
    {
        return SizeClass::from(Input::string($this->validated('size_class')));
    }

    /**
     * @return array{date: string, start_minutes: int, detailers_count: int|null, detailer_ids: list<string>, addons: list<array{addon_id?: string|null, name?: string|null, quantity?: string|null, unit_price_cents?: int|null}>, notes: string|null, customer_name: string|null, customer_email: string|null, customer_phone: string|null, vehicle_label: string|null, bay_id: string|null, status: BookingStatus}
     */
    public function booking(): array
    {
        return [
            'date' => Input::string($this->validated('date')),
            'start_minutes' => BookingRules::minutes($this->validated('start_time')),
            'detailers_count' => $this->filled('detailers_count') ? Input::int($this->validated('detailers_count')) : null,
            'detailer_ids' => BookingRules::ids($this->validated('detailer_ids')),
            'addons' => BookingRules::addonRows($this->validated('addons')),
            'notes' => Input::text($this->validated('notes')),
            'customer_name' => Input::text($this->validated('customer_name')),
            'customer_email' => Input::text($this->validated('customer_email')),
            'customer_phone' => Input::text($this->validated('customer_phone')),
            'vehicle_label' => Input::text($this->validated('vehicle_label')),
            'bay_id' => $this->filled('bay_id') ? Input::id($this->validated('bay_id')) : null,
            'status' => BookingStatus::from($this->filled('status') ? Input::string($this->validated('status')) : 'pending'),
        ];
    }
}
