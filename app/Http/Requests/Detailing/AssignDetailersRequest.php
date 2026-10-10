<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;

final class AssignDetailersRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Empty is refused by the engine, in the source's words.
            'detailer_ids' => ['present', 'array', 'max:20'],
            'detailer_ids.*' => ['string', 'ulid'],
        ];
    }

    /**
     * @return list<string>
     */
    public function detailerIds(): array
    {
        return BookingRules::ids($this->validated('detailer_ids'));
    }
}
