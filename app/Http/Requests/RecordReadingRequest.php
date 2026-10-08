<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class RecordReadingRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'value' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
            'read_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Required to save a reading the validation flags as unusual.
            'confirm_warning' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'string', Rule::in(($this->tenant()?->isStaff() ?? false) ? ['manual', 'check_in'] : ['manual'])],
        ];
    }

    public function value(): string
    {
        return $this->string('value')->toString();
    }

    public function readOn(): ?string
    {
        return $this->filled('read_on') ? $this->string('read_on')->toString() : null;
    }

    public function source(): string
    {
        return $this->filled('source') ? $this->string('source')->toString() : 'manual';
    }
}
