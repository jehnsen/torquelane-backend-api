<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Errors\ErrorCode;

/**
 * The request conflicts with current state, e.g. a reused Idempotency-Key with a different body (409).
 */
final class ConflictException extends ApiException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
