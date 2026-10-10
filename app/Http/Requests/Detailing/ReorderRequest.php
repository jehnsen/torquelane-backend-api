<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

/** The order a branch's packages, categories or add-ons are listed in. */
final class ReorderRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'kind' => ['required', 'string', 'in:packages,categories,addons'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string', 'ulid'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    public function kind(): string
    {
        return Input::string($this->validated('kind'));
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return BookingRules::ids($this->validated('ids'));
    }
}
