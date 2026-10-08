<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Errors\ErrorCode;

/**
 * 403 `forbidden` when an authenticated session resolves to no tenant context
 * (fail closed). `details.reason` is the ScopeDenial value, so the refusal is
 * diagnosable; the same reason is logged.
 */
final class TenantAccessDeniedException extends ApiException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::Forbidden;
    }
}
