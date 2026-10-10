<?php

declare(strict_types=1);

namespace App\Domain\Equipment;

/**
 * Something that put runtime on a tool: a completed job of a package the
 * tool is mapped to (its profile's hours, one cycle), or an entry of the
 * usage log (hours and cycles as logged).
 */
final readonly class UsageEvent
{
    private function __construct(
        public int $atMs,
        public float $hours,
        public int $cycles,
        public bool $isJob,
    ) {}

    public static function job(int $completedAtMs, float $hoursPerJob): self
    {
        return new self($completedAtMs, $hoursPerJob, 1, true);
    }

    /** Logged cycles are whole and never negative, as the source truncated them. */
    public static function logged(int $usedAtMs, float $hours, int $cycles): self
    {
        return new self($usedAtMs, $hours, max(0, $cycles), false);
    }
}
