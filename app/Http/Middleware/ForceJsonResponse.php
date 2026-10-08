<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API speaks JSON only. Forcing the Accept header means the framework
 * never takes an HTML or redirect branch (e.g. the auth guard redirecting a
 * guest to a `login` route that does not exist).
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        /** @var Response */
        return $next($request);
    }
}
