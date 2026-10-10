<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

final class SignOffRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'passed' => ['required', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function passed(): bool
    {
        return $this->boolean('passed');
    }

    public function notes(): ?string
    {
        return Input::text($this->validated('notes'));
    }
}
