<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\SizeClass;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

final class SaveSettingsRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'detailer_pool_size' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'default_detailers_per_booking' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'closing_time' => BookingRules::time('sometimes'),
            'public_booking_enabled' => ['sometimes', 'boolean'],
            'public_booking_notice' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'inspection_required' => ['sometimes', 'boolean'],
            'size_multipliers' => ['sometimes', 'array'],
        ];
        foreach (SizeClass::values() as $size) {
            $rules["size_multipliers.{$size}"] = ['sometimes', 'numeric', 'gt:0', 'max:10', 'regex:/^\d+(\.\d{1,3})?$/'];
        }

        return $rules;
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    /**
     * @return array{detailer_pool_size?: int, default_detailers_per_booking?: int, closing_minutes?: int, public_booking_enabled?: bool, public_booking_notice?: string|null, inspection_required?: bool, size_multipliers?: array<string, string>}
     */
    public function settings(): array
    {
        $data = [];
        foreach (['detailer_pool_size', 'default_detailers_per_booking'] as $number) {
            if ($this->filled($number)) {
                $data[$number] = Input::int($this->validated($number));
            }
        }
        if ($this->filled('closing_time')) {
            $data['closing_minutes'] = BookingRules::minutes($this->validated('closing_time'));
        }
        foreach (['public_booking_enabled', 'inspection_required'] as $flag) {
            if ($this->has($flag)) {
                $data[$flag] = $this->boolean($flag);
            }
        }
        if ($this->has('public_booking_notice')) {
            $data['public_booking_notice'] = Input::text($this->input('public_booking_notice'));
        }
        $multipliers = $this->validated('size_multipliers');
        if (is_array($multipliers)) {
            $data['size_multipliers'] = [];
            foreach ($multipliers as $size => $factor) {
                $data['size_multipliers'][(string) $size] = Input::string($factor);
            }
        }

        return $data;
    }
}
