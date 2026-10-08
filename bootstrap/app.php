<?php

declare(strict_types=1);

use App\Http\Errors\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        $middleware->alias(['idempotent' => EnforceIdempotency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API is JSON only; there is no HTML error page to fall back to.
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request),
        );
    })->create();
