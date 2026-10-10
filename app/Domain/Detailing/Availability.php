<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * Whether a booking could start at a given time: the source's
 * `getTimeAvailability`, which its booking screens ran in the browser. Here
 * the API answers, over the same occupancy the booking itself is decided on.
 *
 * A start is unavailable when it cannot host a single slot (`overflow`), when
 * any slot of its plan is blocked (`blocked`), or when the tightest slot has
 * fewer detailers free than the package needs (`capacity`). Running past
 * closing is not a reason: the plan rolls into the next day.
 */
final class Availability
{
    public const string OVERFLOW = 'overflow';

    public const string BLOCKED = 'blocked';

    public const string CAPACITY = 'capacity';

    /**
     * @param  array<string, true>  $blocked  keys `"Y-m-d|slot"`
     * @return array{available: bool, reason: string|null, remaining: int}
     */
    public static function of(DayPlan $plan, Occupancy $occupancy, array $blocked, int $pool, int $minDetailers): array
    {
        if ($plan->isEmpty()) {
            return ['available' => false, 'reason' => self::OVERFLOW, 'remaining' => 0];
        }
        $cells = $plan->cells();
        foreach ($cells as $cell) {
            if (isset($blocked[$cell['date'].'|'.$cell['slot']])) {
                return ['available' => false, 'reason' => self::BLOCKED, 'remaining' => 0];
            }
        }

        $remaining = $occupancy->minRemaining($cells, $pool) ?? $pool;
        if ($remaining < max(1, $minDetailers)) {
            return ['available' => false, 'reason' => self::CAPACITY, 'remaining' => max(0, $remaining)];
        }

        return ['available' => true, 'reason' => null, 'remaining' => $remaining];
    }
}
