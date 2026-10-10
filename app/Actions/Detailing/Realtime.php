<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Domain\Detailing\MonitorChannel;
use Illuminate\Validation\ValidationException;

/**
 * What a screen needs to listen for booking changes, and the authorisation of
 * one socket for one branch's channel. When no realtime server is configured
 * `enabled` is false and the screens refresh on a timer instead.
 */
final class Realtime
{
    /** How often a screen should re-read when it cannot listen. */
    public const int POLL_SECONDS = 20;

    /**
     * @return array{enabled: bool, key: string|null, host: string|null, port: int|null, scheme: string|null, channel: string, event: string, poll_seconds: int}
     */
    public function client(string $branchId): array
    {
        $key = config('broadcasting.client.key');
        $host = config('broadcasting.client.host');
        $port = config('broadcasting.client.port');
        $scheme = config('broadcasting.client.scheme');
        $enabled = config('broadcasting.default') === 'reverb' && is_string($key) && $key !== '' && is_string($host) && $host !== '';

        return [
            'enabled' => $enabled,
            'key' => $enabled ? $key : null,
            'host' => $enabled ? $host : null,
            'port' => $enabled && is_int($port) ? $port : null,
            'scheme' => $enabled && is_string($scheme) ? $scheme : null,
            'channel' => MonitorChannel::wire($branchId),
            'event' => '.booking.changed',
            'poll_seconds' => self::POLL_SECONDS,
        ];
    }

    /**
     * Sign `$socketId` onto the channel of `$branchId`. The caller has already
     * established that the session or display token may read that branch.
     *
     * @return array{auth: string}
     */
    public function authorize(string $branchId, string $socketId, string $wireChannel): array
    {
        if (! MonitorChannel::isSocketId($socketId)) {
            throw ValidationException::withMessages(['socket_id' => 'That is not a socket id.']);
        }
        if (MonitorChannel::branchOf($wireChannel) !== $branchId) {
            throw ValidationException::withMessages(['channel_name' => 'That is not this branch\'s channel.']);
        }
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages(['channel_name' => 'Realtime is not configured on this server.']);
        }

        return ['auth' => MonitorChannel::signature($key, $secret, $socketId, $wireChannel)];
    }
}
