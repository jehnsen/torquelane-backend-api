<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Access\PersonName;

final class UpdateProfileRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            // Letters, digits, dot, dash, underscore; 3–32 characters.
            'username' => ['required', 'string', 'regex:'.PersonName::USERNAME_PATTERN],
        ];
    }

    public function firstName(): string
    {
        return trim($this->string('first_name')->toString());
    }

    public function lastName(): string
    {
        return trim($this->string('last_name')->toString());
    }

    public function username(): string
    {
        return trim($this->string('username')->toString());
    }
}
