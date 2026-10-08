<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\EnsureSchemaExists;
use App\Database\SchemaMacros;
use App\Http\Errors\ApiExceptionRenderer;
use App\Models\User;
use App\OpenApi\OpenApiConfiguration;
use App\Tenancy\TenantAwareUserProvider;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(ApiExceptionRenderer::class)
            ->needs('$debug')
            ->give(fn (): bool => (bool) config('app.debug'));

        // One tenant context per request (and per queued job).
        $this->app->scoped(TenantManager::class);
    }

    public function boot(): void
    {
        // Mutating a shared Carbon instance is a classic date bug; immutable everywhere.
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! $this->app->isProduction());

        // migrate:fresh / db:wipe only ever make sense on a developer's machine or in tests.
        DB::prohibitDestructiveCommands(! $this->app->environment('local', 'testing'));

        SchemaMacros::register();
        Event::listen(CommandStarting::class, EnsureSchemaExists::class);

        $this->bootAuthentication();
        $this->bootRateLimits();

        OpenApiConfiguration::boot();
    }

    private function bootAuthentication(): void
    {
        Auth::provider('tenant-users', function (Application $app, array $config): TenantAwareUserProvider {
            $model = $config['model'] ?? User::class;

            return new TenantAwareUserProvider($app->make(Hasher::class), is_string($model) ? $model : User::class);
        });

        // Cookie-based SPA auth only: a bearer token is never read.
        Sanctum::getAccessTokenFromRequestUsing(fn (Request $request): ?string => null);

        ResetPassword::createUrlUsing(fn (mixed $user, string $token): string => sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim(config()->string('app.frontend_url'), '/'),
            $token,
            urlencode($user instanceof User ? $user->email : ''),
        ));
    }

    private function bootRateLimits(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(120)->by(is_string($userId) ? 'user:'.$userId : 'ip:'.$request->ip());
        });

        // Credential guessing: per email + IP, and per IP across emails.
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by('login:'.mb_strtolower($request->string('email')->toString()).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);

        // Reset requests send mail; reset and invitation tokens must not be guessable by volume.
        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(3)->by('reset:'.mb_strtolower($request->string('email')->toString()).'|'.$request->ip()),
            Limit::perMinute(10)->by('reset-ip:'.$request->ip()),
        ]);
    }
}
