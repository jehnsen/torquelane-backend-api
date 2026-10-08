<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\System\HealthReport;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property HealthReport $resource
 */
final class HealthResource extends JsonResource
{
    /**
     * @return array{
     *     status: 'ok'|'degraded'|'down',
     *     checked_at: string,
     *     checks: array{
     *         database: array{status: 'ok'|'degraded'|'down', latency_ms: int|null},
     *         queue: array{status: 'ok'|'degraded'|'down', connection: string, pending_jobs: int|null, last_heartbeat_at: string|null},
     *     },
     * }
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        return [
            'status' => $report->status()->value,
            'checked_at' => self::utc($report->checkedAt),
            'checks' => [
                'database' => [
                    'status' => $report->database->status->value,
                    'latency_ms' => $report->database->latencyMs,
                ],
                'queue' => [
                    'status' => $report->queue->status->value,
                    'connection' => $report->queue->connection,
                    'pending_jobs' => $report->queue->pendingJobs,
                    'last_heartbeat_at' => $report->queue->lastHeartbeatAt === null
                        ? null
                        : self::utc($report->queue->lastHeartbeatAt),
                ],
            ],
        ];
    }

    private static function utc(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
