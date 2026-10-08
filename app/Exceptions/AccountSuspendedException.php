<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Errors\ErrorCode;

/**
 * 403 `account_suspended`: a portal user of a suspended customer account, or
 * new work (an invitation now; work orders and sales later) for one.
 */
final class AccountSuspendedException extends ApiException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::AccountSuspended;
    }
}
