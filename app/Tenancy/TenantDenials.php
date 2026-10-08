<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\ScopeDenial;
use App\Exceptions\AccountSuspendedException;
use App\Exceptions\TenantAccessDeniedException;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Log;

/**
 * One mapping from a ScopeDenial to what the caller sees, shared by the
 * tenant middleware and login: `no_session` is 401, `account_suspended` is
 * 403 account_suspended, everything else 403 forbidden. Every denial is
 * logged with its reason and returned in `details.reason`.
 */
final class TenantDenials
{
    public static function exceptionFor(ScopeDenial $denial, string $userId, string $where): Exception
    {
        Log::warning('tenant.denied', ['reason' => $denial->value, 'user_id' => $userId, 'where' => $where]);

        return match ($denial) {
            ScopeDenial::NoSession => new AuthenticationException,
            ScopeDenial::AccountSuspended => new AccountSuspendedException($denial->message(), ['reason' => $denial->value]),
            default => new TenantAccessDeniedException($denial->message(), ['reason' => $denial->value]),
        };
    }
}
