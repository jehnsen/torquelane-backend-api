<?php

declare(strict_types=1);

namespace App\Http\Errors;

use Illuminate\Http\JsonResponse;

/**
 * Builds `{ "error": { "code", "message", "details"? } }`, the only shape an
 * error response from this API ever takes.
 */
final class ErrorEnvelope
{
    /**
     * @param  array<string, mixed>|null  $details  omitted from the body when null
     * @param  array<string, string>  $headers
     */
    public static function response(
        ErrorCode $code,
        ?string $message = null,
        ?array $details = null,
        ?int $status = null,
        array $headers = [],
    ): JsonResponse {
        $error = [
            'code' => $code->value,
            'message' => $message ?? $code->defaultMessage(),
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status ?? $code->status(), $headers);
    }
}
