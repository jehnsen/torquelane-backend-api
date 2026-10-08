<?php

declare(strict_types=1);

namespace App\Domain\System;

use DateTimeImmutable;

final readonly class QueueCheck
{
    public function __construct(
        public CheckStatus $status,
        public string $connection,
        public ?int $pendingJobs,
        public ?DateTimeImmutable $lastHeartbeatAt,
    ) {}
}
