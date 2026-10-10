<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Shared\Calendar;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One recurring maintenance item's limits: an interval per meter and/or a
 * calendar interval. The item falls due on whichever limit arrives first.
 *
 * The calendar interval is counted in months (a vehicle's schedule) or in
 * days (a tool's, Phase 10), never both.
 */
final readonly class PlanItem
{
    /**
     * @param  array<string, float|int>  $meterIntervals  MeterKind value → amount, in evaluation order
     */
    public function __construct(
        public array $meterIntervals,
        public ?int $calendarMonths,
        public ?int $calendarDays = null,
    ) {
        if ($meterIntervals === [] && $calendarMonths === null && $calendarDays === null) {
            throw new InvalidArgumentException('A plan item needs at least one limit.');
        }
        if ($calendarMonths !== null && $calendarDays !== null) {
            throw new InvalidArgumentException('A plan item counts its calendar interval in months or in days, not both.');
        }
    }

    /** The calendar limit `$times` intervals after `$from`; null when the item has none. */
    public function calendarLimit(DateTimeImmutable $from, int $times = 1): ?DateTimeImmutable
    {
        return match (true) {
            $this->calendarMonths !== null => Calendar::addMonths($from, $this->calendarMonths * $times),
            $this->calendarDays !== null => Calendar::addDays($from, $this->calendarDays * $times),
            default => null,
        };
    }
}
