<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SaveVendorRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->presence(), 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name?: string, is_active?: bool}
     */
    public function vendorAttributes(): array
    {
        $attributes = [];
        if ($this->has('name')) {
            $attributes['name'] = trim($this->string('name')->toString());
        }
        if ($this->has('is_active')) {
            $attributes['is_active'] = $this->boolean('is_active');
        }

        return $attributes;
    }
}
