<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Audit\AuditTrail;
use App\Domain\Tenancy\ScopeDenial;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantDenials;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Cookie-session login for the SPA. Credentials first (one generic message
 * for unknown email and wrong password), then the same tenant resolution
 * every request gets: a session that would resolve to no scope (a suspended
 * account's portal user, a disabled user, a suspended organization) is
 * signed straight back out and refused with its reason, rather than handed a
 * cookie that fails on the next request.
 */
final class Login
{
    public function __construct(
        private readonly TenantContextResolver $resolver,
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @return array{0: User, 1: TenantContext}
     */
    public function handle(Request $request, string $email, string $password, bool $remember): array
    {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Sign in from the TorqueLane app: login needs a cookie session (call /api/v1/sanctum/csrf-cookie first, from an allowed origin).');
        }

        $guard = Auth::guard('web');
        if (! $guard->attempt(['email' => mb_strtolower(trim($email)), 'password' => $password], $remember)) {
            throw ValidationException::withMessages(['email' => "That email and password combination isn't recognised."]);
        }

        $user = $guard->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['email' => "That email and password combination isn't recognised."]);
        }

        $resolution = $this->resolver->resolve($user, null);
        if ($resolution->context === null) {
            $guard->logout();
            throw TenantDenials::exceptionFor($resolution->denial ?? ScopeDenial::NoSession, $user->id, 'login');
        }

        $request->session()->regenerate();

        $context = $resolution->context;
        $this->tenancy->actingAs($context, fn () => DB::transaction(function () use ($user): void {
            $user->forceFill(['last_login_at' => CarbonImmutable::now('UTC')])->save();
            $this->audit->record($user, 'logged_in', null, null);
        }));

        return [$user, $context];
    }
}
