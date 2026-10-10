<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\DetailingDisplayToken;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A wall screen's credential: `X-Display-Token`, a per-branch token made
 * under Detailing settings. It reads that branch's shop monitor and nothing
 * else; a revoked or unknown token is 401. There is no user and no session.
 *
 * The token is found by its SHA-256 in a named system context (there is no
 * tenant yet), and the request carries it on as `display_token`.
 */
final class AuthenticateDisplayToken
{
    public const string ATTRIBUTE = 'display_token';

    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->header('X-Display-Token');
        if (! is_string($plain) || strlen($plain) < 16 || strlen($plain) > 128) {
            throw new AuthenticationException('This screen needs a display token.');
        }

        $token = $this->tenancy->system('display token', function () use ($plain): ?DetailingDisplayToken {
            $token = DetailingDisplayToken::query()->where('token_hash', DetailingDisplayToken::hash($plain))->first();
            if ($token === null || $token->revoked_at !== null) {
                return null;
            }
            // Often enough to see a screen is alive, not on every poll.
            if ($token->last_used_at === null || $token->last_used_at->lt(CarbonImmutable::now()->subMinute())) {
                $token->forceFill(['last_used_at' => CarbonImmutable::now()])->save();
            }

            return $token;
        });
        if ($token === null) {
            throw new AuthenticationException('This display token is not valid, or has been revoked.');
        }

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $next($request);
    }
}
