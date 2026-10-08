<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class TransferVehicleRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_account_id' => ['required', 'string', 'ulid'],
            'effective_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function effectiveOn(): ?string
    {
        return $this->filled('effective_on') ? $this->string('effective_on')->toString() : null;
    }
}
