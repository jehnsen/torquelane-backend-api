<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a caller's X-Request-Id (or mints one) and returns it on the response.
 *
 * The id goes into Laravel's Context, which appends it to every log record and
 * carries it into queued jobs dispatched during the request. Audit rows (Phase
 * 1) read it from `Context::get('request_id')`.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /** Printable, header-safe and short enough to index. Anything else is replaced, not trusted. */
    private const PATTERN = '/\A[A-Za-z0-9._:\-]{1,128}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : (string) Str::ulid();

        $request->headers->set(self::HEADER, $id);
        Context::add('request_id', $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
