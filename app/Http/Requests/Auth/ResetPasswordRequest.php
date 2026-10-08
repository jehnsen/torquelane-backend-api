<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

final class ResetPasswordRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // Ported minimum from ../web (8 characters).
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
