<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SetModuleRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
