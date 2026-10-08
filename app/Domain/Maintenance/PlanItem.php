<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use InvalidArgumentException;

/**
 * One recurring maintenance item's limits: an interval per meter and/or a
 * calendar interval. The item falls due on whichever limit arrives first.
 */
final readonly class PlanItem
{
    /**
     * @param  array<string, float|int>  $meterIntervals  MeterKind value → amount, in evaluation order
     */
    public function __construct(
        public array $meterIntervals,
        public ?int $calendarMonths,
    ) {
        if ($meterIntervals === [] && $calendarMonths === null) {
            throw new InvalidArgumentException('A plan item needs at least one limit.');
        }
    }
}
