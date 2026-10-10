<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\SlotGrid;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use Illuminate\Validation\Rule;

/** Close slots of one day to new bookings. `slots` are grid slot starts, minutes since midnight. */
final class BlockSlotsRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'slots' => ['required', 'array', 'min:1', 'max:'.count(SlotGrid::slots())],
            'slots.*' => ['integer', Rule::in(SlotGrid::slots())],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    public function day(): string
    {
        return Input::string($this->validated('date'));
    }

    /**
     * @return list<int>
     */
    public function slots(): array
    {
        $slots = $this->validated('slots');

        return is_array($slots) ? array_values(array_map(Input::int(...), $slots)) : [];
    }

    public function label(): ?string
    {
        return Input::text($this->validated('label'));
    }
}
