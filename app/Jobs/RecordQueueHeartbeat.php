<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\System\CheckHealth;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched by the scheduler every minute; stamps when a worker ran it.
 * The health endpoint reads the stamp (see QueueHeartbeat).
 */
final class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(Repository $cache): void
    {
        $cache->forever(CheckHealth::HEARTBEAT_CACHE_KEY, now()->utc()->toIso8601String());
    }
}
