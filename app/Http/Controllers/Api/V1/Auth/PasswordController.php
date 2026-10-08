<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResetPassword;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

final class PasswordController
{
    /**
     * Request a password reset
     *
     * Emails a reset link when the address belongs to an account. Answers the
     * same either way, so it cannot be used to discover accounts.
     * Rate-limited.
     *
     * @unauthenticated
     */
    public function forgot(ForgotPasswordRequest $request, ResetPassword $reset): JsonResponse
    {
        $reset->sendLink($request->string('email')->toString());

        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }

    /**
     * Reset a password
     *
     * Uses the token from the reset email. Rate-limited.
     *
     * @unauthenticated
     */
    public function reset(ResetPasswordRequest $request, ResetPassword $reset): JsonResponse
    {
        $reset->reset(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return new JsonResponse(['data' => ['status' => 'reset']]);
    }
}
