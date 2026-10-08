<?php

declare(strict_types=1);

namespace App\Http\Errors;

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Maps every exception — ours and the framework's — onto the error envelope.
 *
 * Registered as a render callback in bootstrap/app.php. Laravel has already
 * run `prepareException()` by the time this sees anything, so
 * ModelNotFoundException arrives as a 404 HttpException and
 * AuthorizationException as a 403 (or whatever status a policy's
 * `denyAsNotFound()` chose).
 */
final class ApiExceptionRenderer
{
    public function __construct(private readonly bool $debug) {}

    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        return match (true) {
            // A response somebody built on purpose; leave it alone.
            $e instanceof HttpResponseException => null,
            $e instanceof ApiException => ErrorEnvelope::response($e->errorCode(), $e->getMessage(), $e->details()),
            $e instanceof AuthenticationException => ErrorEnvelope::response(ErrorCode::Unauthenticated),
            $e instanceof ValidationException => ErrorEnvelope::response(
                ErrorCode::Validation,
                $e->getMessage(),
                ['fields' => $e->errors()],
            ),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),
            default => $this->serverError($e),
        };
    }

    private function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        /** @var array<string, string> $headers */
        $headers = $e->getHeaders();

        $code = match (true) {
            $status === 401 => ErrorCode::Unauthenticated,
            $status === 403 => ErrorCode::Forbidden,
            $status === 404 => ErrorCode::NotFound,
            $status === 405 => ErrorCode::MethodNotAllowed,
            $status === 409 => ErrorCode::Conflict,
            $status === 422 => ErrorCode::Validation,
            $status === 429 => ErrorCode::RateLimited,
            $status >= 500 => ErrorCode::ServerError,
            default => ErrorCode::BadRequest,
        };

        // A 404's message can name the model ("No query results for model
        // [App\Models\WorkOrder]"), which tells a caller what kind of record
        // they guessed at in another tenant. Framework-worded codes use the
        // generic text; a 403 keeps the policy's own reason.
        $message = match (true) {
            $code === ErrorCode::NotFound,
            $code === ErrorCode::ServerError,
            $code === ErrorCode::MethodNotAllowed,
            $code === ErrorCode::RateLimited => null,
            $e->getMessage() === '' => null,
            default => $e->getMessage(),
        };

        return ErrorEnvelope::response($code, $message, status: $status, headers: $headers);
    }

    private function serverError(Throwable $e): JsonResponse
    {
        $details = $this->debug ? [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'location' => $e->getFile().':'.$e->getLine(),
        ] : null;

        return ErrorEnvelope::response(ErrorCode::ServerError, details: $details);
    }
}
