<?php

declare(strict_types=1);

namespace App\Domain\System;

use DateTimeImmutable;

/**
 * Judges the queue by its heartbeat: the scheduler dispatches a job every
 * minute and the worker stamps the time it ran. A recent stamp proves both
 * the cron entry and the supervisor-managed worker are alive; queue depth
 * alone proves neither.
 */
final class QueueHeartbeat
{
    public const STALE_AFTER_SECONDS = 300;

    public static function assess(?DateTimeImmutable $lastBeat, DateTimeImmutable $now): CheckStatus
    {
        if ($lastBeat === null) {
            return CheckStatus::Down;
        }

        $age = $now->getTimestamp() - $lastBeat->getTimestamp();

        return $age <= self::STALE_AFTER_SECONDS ? CheckStatus::Ok : CheckStatus::Down;
    }
}
