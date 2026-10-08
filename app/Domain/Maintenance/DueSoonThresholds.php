<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/** How far ahead of a limit an item enters the warning band. */
final readonly class DueSoonThresholds
{
    /**
     * @param  array<string, float|int>  $meterAmounts  MeterKind value → amount
     */
    public function __construct(
        public array $meterAmounts,
        public int $days,
    ) {}
}
