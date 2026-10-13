<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Tax\Atc;

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
            // What a bill, a 2307 and the summary list of purchases print (Phase 12).
            'registered_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tin' => ['sometimes', 'nullable', 'string', 'regex:'.self::TIN],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_vat_registered' => ['sometimes', 'boolean'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            // The tax usually withheld from this vendor: both, or neither.
            'default_atc_code' => ['sometimes', 'nullable', 'string', 'regex:'.Atc::PATTERN, 'required_with:default_withholding_rate_pct'],
            'default_withholding_rate_pct' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,3', 'required_with:default_atc_code'],
        ];
    }

    /** An ATC is written in capitals. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('default_atc_code'))) {
            $this->merge(['default_atc_code' => strtoupper(trim($this->input('default_atc_code')))]);
        }
    }

    /**
     * @return array{name?: string, is_active?: bool, registered_name?: string|null, tin?: string|null, address?: string|null, is_vat_registered?: bool, payment_terms_days?: int, default_atc_code?: string|null, default_withholding_rate_pct?: string|null}
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
        foreach (['registered_name', 'tin', 'address', 'default_atc_code', 'default_withholding_rate_pct'] as $key) {
            if ($this->has($key)) {
                $attributes[$key] = Input::text($this->validated($key));
            }
        }
        if ($this->has('is_vat_registered')) {
            $attributes['is_vat_registered'] = $this->boolean('is_vat_registered');
        }
        if ($this->has('payment_terms_days')) {
            $attributes['payment_terms_days'] = $this->integer('payment_terms_days');
        }

        return $attributes;
    }
}
