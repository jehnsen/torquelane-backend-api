<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\BookingStatus;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use Illuminate\Validation\Rule;

/**
 * Move a booking's status. `reason` is kept on a cancellation and logged on
 * any move; `inspection_override_reason` starts work without the check-in
 * inspection.
 */
final class ChangeBookingStatusRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(BookingStatus::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'inspection_override_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function status(): BookingStatus
    {
        return BookingStatus::from(Input::string($this->validated('status')));
    }

    public function reason(): ?string
    {
        return Input::text($this->validated('reason'));
    }

    public function inspectionOverride(): ?string
    {
        return Input::text($this->validated('inspection_override_reason'));
    }
}
