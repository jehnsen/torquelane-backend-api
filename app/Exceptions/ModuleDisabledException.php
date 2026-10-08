<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Errors\ErrorCode;

/**
 * The organization is not entitled to the module this route belongs to (403).
 */
final class ModuleDisabledException extends ApiException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::ModuleDisabled;
    }
}
