<?php

declare(strict_types=1);

use App\Domain\System\CheckStatus;
use App\Domain\System\DatabaseCheck;
use App\Domain\System\HealthReport;
use App\Domain\System\QueueCheck;
use App\Domain\System\QueueHeartbeat;

function healthReport(CheckStatus $database, CheckStatus $queue): HealthReport
{
    return new HealthReport(
        new DatabaseCheck($database, 1),
        new QueueCheck($queue, 'database', 0, null),
        new DateTimeImmutable('2026-10-08T00:00:00Z'),
    );
}

it('derives overall status from the checks', function (CheckStatus $database, CheckStatus $queue, CheckStatus $overall) {
    expect(healthReport($database, $queue)->status())->toBe($overall);
})->with([
    'all ok' => [CheckStatus::Ok, CheckStatus::Ok, CheckStatus::Ok],
    'queue down only degrades' => [CheckStatus::Ok, CheckStatus::Down, CheckStatus::Degraded],
    'database down is down' => [CheckStatus::Down, CheckStatus::Ok, CheckStatus::Down],
    'both down is down' => [CheckStatus::Down, CheckStatus::Down, CheckStatus::Down],
]);

it('treats a missing heartbeat as a dead queue', function () {
    expect(QueueHeartbeat::assess(null, new DateTimeImmutable))->toBe(CheckStatus::Down);
});

it('accepts a heartbeat up to five minutes old', function (int $ageSeconds, CheckStatus $status) {
    $now = new DateTimeImmutable('2026-10-08T12:00:00Z');

    expect(QueueHeartbeat::assess($now->modify("-{$ageSeconds} seconds"), $now))->toBe($status);
})->with([
    'fresh' => [30, CheckStatus::Ok],
    'at the limit' => [QueueHeartbeat::STALE_AFTER_SECONDS, CheckStatus::Ok],
    'stale' => [QueueHeartbeat::STALE_AFTER_SECONDS + 1, CheckStatus::Down],
]);
