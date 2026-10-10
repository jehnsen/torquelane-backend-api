<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Detailing\DetailingQueries;
use App\Actions\Detailing\Realtime;
use App\Domain\Shared\Calendar;
use App\Http\Middleware\AuthenticateDisplayToken;
use App\Models\Branch;
use App\Models\DetailingDisplayToken;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shop monitor on a wall screen (the TV view). No user signs in: the
 * screen holds a display token (`X-Display-Token`) that reads ONE branch's
 * monitor, read-only, until staff revoke it.
 */
final class DisplayController
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly DetailingQueries $queries,
        private readonly Realtime $realtime,
    ) {}

    /**
     * The shop monitor, for a display
     *
     * Today's confirmed, on-going and completed jobs of the token's branch
     * (Asia/Manila), the branch's name and look, and how to listen for
     * changes (`realtime`; when it is not enabled, read again every
     * `poll_seconds`).
     */
    public function monitor(Request $request): JsonResponse
    {
        $token = self::token($request);

        return new JsonResponse(['data' => $this->tenancy->system('shop monitor display', function () use ($token): array {
            $branch = Branch::query()->findOrFail($token->branch_id);

            return [
                'branch' => [
                    'id' => $branch->id,
                    'name' => $branch->brand_name ?? $branch->name,
                    'logo_url' => $branch->logo_url,
                    'brand_color' => $branch->brand_color,
                    'theme_tokens' => $branch->theme_tokens,
                ],
                'display' => ['name' => $token->name],
                'monitor' => $this->queries->monitor($branch, Calendar::toDate(CarbonImmutable::now())),
                'realtime' => $this->realtime->client($branch->id),
            ];
        })], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Authorise a display's socket
     *
     * Signs `socket_id` onto `channel_name`, which must be the token's own
     * branch channel.
     */
    public function authorizeChannel(Request $request): JsonResponse
    {
        $token = self::token($request);
        $socket = $request->input('socket_id');
        $channel = $request->input('channel_name');

        return new JsonResponse($this->realtime->authorize($token->branch_id, is_string($socket) ? $socket : '', is_string($channel) ? $channel : ''));
    }

    private static function token(Request $request): DetailingDisplayToken
    {
        $token = $request->attributes->get(AuthenticateDisplayToken::ATTRIBUTE);

        return $token instanceof DetailingDisplayToken ? $token : throw new AuthenticationException('This screen needs a display token.');
    }
}
