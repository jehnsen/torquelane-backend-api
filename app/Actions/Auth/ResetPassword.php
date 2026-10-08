<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Audit\AuditTrail;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forgot / reset password through Laravel's broker (tokens in
 * password_reset_tokens, 60-minute expiry, one request per minute per
 * account). The user lookup goes through TenantAwareUserProvider.
 *
 * `sendLink` answers the same whether or not the email exists, so it cannot
 * be used to discover accounts.
 */
final class ResetPassword
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function sendLink(string $email): void
    {
        Password::broker()->sendResetLink(['email' => mb_strtolower(trim($email))]);
    }

    public function reset(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => mb_strtolower(trim($email)), 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                    $this->audit->record($user, 'password_reset', null, null, $user);
                });
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or has expired. Request a new one.']);
        }
    }
}
