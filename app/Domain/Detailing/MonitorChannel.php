<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * The realtime channel of a branch's detailing floor, and the Pusher-protocol
 * signature that lets one socket listen to it. Reverb speaks that protocol:
 * a private channel is joined with `key:HMAC-SHA256(secret, "socket:channel")`.
 */
final class MonitorChannel
{
    /** The channel as events are broadcast on it (Laravel adds `private-`). */
    public static function name(string $branchId): string
    {
        return 'detailing.branch.'.$branchId;
    }

    /** The channel as a client subscribes to it. */
    public static function wire(string $branchId): string
    {
        return 'private-'.self::name($branchId);
    }

    /** The branch a wire channel name is for; null when it is not one of these channels. */
    public static function branchOf(string $wireChannel): ?string
    {
        return preg_match('/^private-detailing\.branch\.([0-9a-z]{26})$/', $wireChannel, $m) === 1 ? $m[1] : null;
    }

    public static function isSocketId(string $socketId): bool
    {
        return preg_match('/^\d+\.\d+$/', $socketId) === 1;
    }

    public static function signature(string $key, string $secret, string $socketId, string $wireChannel): string
    {
        return $key.':'.hash_hmac('sha256', $socketId.':'.$wireChannel, $secret);
    }
}
