<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

final class TickChecklistRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'index' => ['required', 'integer', 'min:0', 'max:200'],
            'done' => ['required', 'boolean'],
        ];
    }

    public function index(): int
    {
        return Input::int($this->validated('index'));
    }

    public function done(): bool
    {
        return $this->boolean('done');
    }
}
