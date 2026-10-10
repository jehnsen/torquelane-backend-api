<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\SizeClass;
use App\Domain\Shared\Calendar;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use Carbon\CarbonImmutable;

/**
 * A read of one branch's detailing: its menu, settings, schedule, monitor or
 * availability. `branch_id` may be left out by a session that works in one
 * detailing branch; dates default to today in Manila.
 */
final class BranchRequest extends ApiRequest
{
    public const int MAX_SPAN_DAYS = 31;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'package_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'size_class' => BookingRules::size('sometimes'),
            'start_time' => BookingRules::time('sometimes'),
            'except_booking_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'include_inactive' => ['sometimes', 'boolean'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    public function day(): string
    {
        return Input::text($this->validated('date')) ?? Calendar::toDate(CarbonImmutable::now());
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function span(): array
    {
        $from = Input::text($this->validated('from')) ?? $this->day();
        $to = Input::text($this->validated('to')) ?? $from;
        $limit = Calendar::toDate(Calendar::addDays(Calendar::parseDate($from), self::MAX_SPAN_DAYS - 1));

        return [$from, min($to, $limit)];
    }

    public function packageId(): ?string
    {
        return $this->filled('package_id') ? Input::id($this->validated('package_id')) : null;
    }

    public function size(): ?SizeClass
    {
        return $this->filled('size_class') ? SizeClass::from(Input::string($this->validated('size_class'))) : null;
    }

    public function startMinutes(): ?int
    {
        return $this->filled('start_time') ? BookingRules::minutes($this->validated('start_time')) : null;
    }

    public function exceptBookingId(): ?string
    {
        return $this->filled('except_booking_id') ? Input::id($this->validated('except_booking_id')) : null;
    }

    public function includeInactive(): bool
    {
        return $this->boolean('include_inactive');
    }
}
