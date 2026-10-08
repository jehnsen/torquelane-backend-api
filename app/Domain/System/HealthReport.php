<?php

declare(strict_types=1);

namespace App\Domain\System;

use DateTimeImmutable;

/**
 * The API is down when its database is; a stalled queue only degrades it,
 * since reads and synchronous writes still work.
 */
final readonly class HealthReport
{
    public function __construct(
        public DatabaseCheck $database,
        public QueueCheck $queue,
        public DateTimeImmutable $checkedAt,
    ) {}

    public function status(): CheckStatus
    {
        return match (true) {
            $this->database->status === CheckStatus::Down => CheckStatus::Down,
            $this->database->status !== CheckStatus::Ok,
            $this->queue->status !== CheckStatus::Ok => CheckStatus::Degraded,
            default => CheckStatus::Ok,
        };
    }
}
