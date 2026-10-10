<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/** One day of a booking's plan: the business date and the slots it takes that day. */
final readonly class PlannedDay
{
    /**
     * @param  list<int>  $slots  slot starts, minutes since midnight, in order
     */
    public function __construct(
        public string $date,
        public array $slots,
    ) {}
}
