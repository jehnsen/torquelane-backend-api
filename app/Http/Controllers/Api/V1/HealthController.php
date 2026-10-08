<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\System\CheckHealth;
use App\Domain\System\CheckStatus;
use App\Http\Errors\ErrorCode;
use App\Http\Errors\ErrorEnvelope;
use App\Http\Resources\HealthResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HealthController
{
    /**
     * Health
     *
     * Database and queue status, for uptime monitors. `degraded` (queue
     * heartbeat missing or older than five minutes) still answers 200; an
     * unreachable database answers 503 in the error envelope, with the report
     * under `details.health`.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, CheckHealth $checkHealth): JsonResponse
    {
        $report = $checkHealth();
        $body = new HealthResource($report);

        $response = $report->status() === CheckStatus::Down
            ? ErrorEnvelope::response(
                ErrorCode::ServerError,
                'The API cannot reach its database.',
                ['health' => $body->resolve($request)],
                503,
            )
            : $body->response($request);

        return $response->header('Cache-Control', 'no-store');
    }
}
