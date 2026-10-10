<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\BookingStatus;
use App\Http\Requests\Input;
use App\Http\Requests\PaginatedRequest;
use Illuminate\Validation\Rule;

final class ListBookingsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', Rule::enum(BookingStatus::class)],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'customer_account_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array{branch_id: string|null, status: list<string>, date_from: string|null, date_to: string|null, q: string|null, customer_account_id: string|null}
     */
    public function filters(): array
    {
        $status = $this->validated('status');

        return [
            'branch_id' => $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null,
            'status' => is_array($status) ? array_values(array_map(Input::string(...), $status)) : [],
            'date_from' => Input::text($this->validated('date_from')),
            'date_to' => Input::text($this->validated('date_to')),
            'q' => Input::text($this->validated('q')),
            'customer_account_id' => $this->filled('customer_account_id') ? Input::id($this->validated('customer_account_id')) : null,
        ];
    }
}
