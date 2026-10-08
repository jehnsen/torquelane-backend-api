<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * What a customer account's status permits.
 *
 * RULE CHANGE from ../web (deliberate). The frontend's suspended client
 * resolved to no scope for its own users, and its database hid it from the
 * provider too. Here:
 *  - portal users of a suspended account are denied outright
 *    (`account_suspended`, 403 — TenantScopeResolver);
 *  - staff can still READ a suspended account and everything beneath it
 *    (collections, history), and may keep its records accurate (contacts,
 *    consent withdrawals);
 *  - staff cannot start NEW WORK for it: no portal invitations now, and every
 *    later phase's "create work" path (work orders, quotes, bookings, sales on
 *    account) must call this through CustomerAccountPolicy::createWorkFor.
 */
final class AccountStanding
{
    public const string ACTIVE = 'active';

    public const string SUSPENDED = 'suspended';

    public static function acceptsNewWork(string $status): bool
    {
        return $status === self::ACTIVE;
    }

    public static function portalMayAccess(string $status): bool
    {
        return $status === self::ACTIVE;
    }

    public static function staffMayRead(string $status): bool
    {
        return in_array($status, [self::ACTIVE, self::SUSPENDED], true);
    }
}
