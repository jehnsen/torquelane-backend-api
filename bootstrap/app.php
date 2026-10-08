<?php

declare(strict_types=1);

use App\Http\Errors\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so even a 404 for an unknown route carries a request id and
        // renders as JSON.
        $middleware->prepend([AssignRequestId::class, ForceJsonResponse::class]);
        $middleware->throttleApi();
        // Cookie-based SPA auth (Sanctum): requests from the stateful domains
        // get a session and CSRF protection.
        $middleware->statefulApi();
        $middleware->alias([
            'idempotent' => EnforceIdempotency::class,
            'tenant' => ResolveTenantContext::class,
        ]);
        // The tenant context must exist before route-model binding runs, so a
        // bound {model} is already organization-scoped (another tenant's id → 404).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API is JSON only; there is no HTML error page to fall back to.
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request),
        );
    })->create();
