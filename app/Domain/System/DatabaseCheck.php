<?php

declare(strict_types=1);

namespace App\Domain\System;

final readonly class DatabaseCheck
{
    public function __construct(
        public CheckStatus $status,
        public ?int $latencyMs,
    ) {}
}
