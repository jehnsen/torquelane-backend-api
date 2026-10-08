<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Errors\ErrorCode;

/**
 * A state-machine transition that is not legal from the record's current status (409).
 */
final class InvalidTransitionException extends ApiException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidTransition;
    }
}
