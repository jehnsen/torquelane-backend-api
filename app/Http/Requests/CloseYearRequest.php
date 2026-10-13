<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** A fiscal (calendar) year: required to close one, optional to look one up. */
final class CloseYearRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['year' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', 'min:2000', 'max:2999']];
    }

    public function year(): ?int
    {
        return $this->filled('year') ? Input::int($this->input('year')) : null;
    }
}
