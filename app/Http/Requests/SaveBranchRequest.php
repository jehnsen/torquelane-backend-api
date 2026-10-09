<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SaveBranchRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->presence(), 'string', 'max:255'],
            'slug' => [$this->presence(), 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tin' => ['sometimes', 'nullable', 'string', 'regex:'.self::TIN],
            'branch_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{3,5}$/'],
            'is_vat_registered' => ['sometimes', 'boolean'],
            'prices_include_vat' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            // What a stock move that would take a balance below zero does in this branch.
            'negative_stock_policy' => ['sometimes', 'string', 'in:allow_and_flag,block'],
            ...self::brandingRules(),
            ...self::themeTokenRules(),
        ];
    }
}
