<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\EnsureSchemaExists;
use App\Database\SchemaMacros;
use App\Http\Errors\ApiExceptionRenderer;
use App\OpenApi\OpenApiConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(ApiExceptionRenderer::class)
            ->needs('$debug')
            ->give(fn (): bool => (bool) config('app.debug'));
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

        RateLimiter::for('api', function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(120)->by(is_string($userId) ? 'user:'.$userId : 'ip:'.$request->ip());
        });

        OpenApiConfiguration::boot();
    }
}
