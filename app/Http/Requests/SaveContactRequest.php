<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SaveContactRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->presence(), 'string', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'receives_invoices' => ['sometimes', 'boolean'],
            'receives_reminders' => ['sometimes', 'boolean'],
        ];
    }
}
