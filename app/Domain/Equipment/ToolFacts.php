<?php

declare(strict_types=1);

namespace App\Domain\Equipment;

/**
 * What the engine needs to know about one tool. Instants are milliseconds
 * since the epoch, as the source's JavaScript held them; a purchase DATE is
 * that day's UTC midnight, which is how the source read a date-only string.
 */
final readonly class ToolFacts
{
    public function __construct(
        public string $id,
        public string $status,
        public string $conditionRating,
        public bool $isActive,
        public int $purchaseCostCents,
        public float $baselineHours,
        public int $baselineCycles,
        public ?float $serviceIntervalHours,
        public ?int $serviceIntervalCycles,
        public ?int $serviceIntervalDays,
        public ?int $lastServiceAtMs,
        public ?int $purchaseDateMs,
        public ?int $createdAtMs,
        public ?float $expectedLifeHours,
    ) {}
}
