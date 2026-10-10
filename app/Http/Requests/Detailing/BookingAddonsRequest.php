<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;

final class BookingAddonsRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = BookingRules::addons();
        $rules['addons'] = ['present', 'array', 'max:30'];

        return $rules;
    }

    /**
     * @return list<array{addon_id?: string|null, name?: string|null, quantity?: string|null, unit_price_cents?: int|null}>
     */
    public function addons(): array
    {
        return BookingRules::addonRows($this->validated('addons'));
    }
}
