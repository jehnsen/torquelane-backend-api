<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

final readonly class IssuedNumber
{
    public function __construct(
        public int $number,
        /** e.g. `WO-2026-0001`. */
        public string $formatted,
        public string $periodKey,
    ) {}
}
