<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** A month, `YYYY-MM`. */
final class ClosePeriodRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['period' => ['required', 'string', 'date_format:Y-m']];
    }

    public function period(): string
    {
        return Input::string($this->input('period'));
    }
}
