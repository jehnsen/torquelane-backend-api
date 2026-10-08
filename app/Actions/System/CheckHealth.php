<?php

declare(strict_types=1);

namespace App\Actions\System;

use App\Domain\System\CheckStatus;
use App\Domain\System\DatabaseCheck;
use App\Domain\System\HealthReport;
use App\Domain\System\QueueCheck;
use App\Domain\System\QueueHeartbeat;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Probes the database and the queue. Read-only, so no transaction. Every probe
 * swallows its own failure: a health check that throws reports nothing.
 */
final class CheckHealth
{
    public const HEARTBEAT_CACHE_KEY = 'health:queue:last_beat_at';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Repository $cache,
        private readonly QueueFactory $queue,
        private readonly Config $config,
    ) {}

    public function __invoke(): HealthReport
    {
        $now = CarbonImmutable::now('UTC');

        return new HealthReport($this->database(), $this->queue($now), $now);
    }

    private function database(): DatabaseCheck
    {
        try {
            $started = hrtime(true);
            $this->db->connection()->select('select 1');

            return new DatabaseCheck(CheckStatus::Ok, intdiv(hrtime(true) - $started, 1_000_000));
        } catch (Throwable) {
            return new DatabaseCheck(CheckStatus::Down, null);
        }
    }

    private function queue(DateTimeImmutable $now): QueueCheck
    {
        $connection = $this->config->string('queue.default');

        try {
            $pending = $this->queue->connection()->size();
        } catch (Throwable) {
            $pending = null;
        }

        try {
            $stamp = $this->cache->get(self::HEARTBEAT_CACHE_KEY);
            $lastBeat = is_string($stamp) ? CarbonImmutable::parse($stamp)->utc() : null;
        } catch (Throwable) {
            $lastBeat = null;
        }

        return new QueueCheck(QueueHeartbeat::assess($lastBeat, $now), $connection, $pending, $lastBeat);
    }
}
