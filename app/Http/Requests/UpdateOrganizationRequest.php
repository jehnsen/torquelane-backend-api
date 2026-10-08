<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class UpdateOrganizationRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tin' => ['sometimes', 'nullable', 'string', 'regex:'.self::TIN],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            ...self::brandingRules(),
            ...self::themeTokenRules(),
        ];
    }
}
