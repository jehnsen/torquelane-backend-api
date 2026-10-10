<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * The capacity rules of the source's `add_booking` and
 * `update_booking_detailers`, as plain decisions over an `Occupancy`.
 *
 * A branch has a pool of detailers. A booking reserves HEADS against it on
 * every (date, slot) it touches; naming specific detailers is optional and
 * they are always a subset of the heads.
 */
final class BookingCapacity
{
    /**
     * A new booking (the source's `add_booking`).
     *
     *  1. heads wanted = the most of: what was asked for (1 when unsaid), the
     *     detailers named, the package's minimum;
     *  2. the tightest slot must still have the package's minimum free, else
     *     the booking is refused (`full`);
     *  3. the reservation is trimmed to what is free, never below the minimum;
     *  4. a named detailer already on another job in any of these slots refuses
     *     the booking (`detailer_conflict`);
     *  5. the names kept are the first `heads` of those given.
     *
     * @param  list<string>  $detailerIds  named detailers, in the order given
     */
    public static function admit(DayPlan $plan, Occupancy $occupancy, int $pool, int $minDetailers, ?int $requestedHeads, array $detailerIds): Admission
    {
        if ($plan->isEmpty()) {
            return Admission::refused(Refusal::InvalidSlot);
        }
        $cells = $plan->cells();
        $minDetailers = max(1, $minDetailers);
        $named = count($detailerIds);
        $headcount = max($requestedHeads ?? 1, $named, $minDetailers, 1);

        $remaining = $occupancy->minRemaining($cells, $pool);
        if ($remaining !== null && $remaining < $minDetailers) {
            return Admission::refused(Refusal::Full, max(0, $remaining));
        }
        if ($remaining !== null) {
            $headcount = max(min($headcount, $remaining), $minDetailers);
        }

        $conflicts = $occupancy->conflicts($cells, $detailerIds);
        if ($conflicts !== []) {
            return Admission::refused(Refusal::DetailerConflict, $remaining, $conflicts);
        }

        return Admission::admitted($headcount, array_slice($detailerIds, 0, min($named, $headcount)), $remaining);
    }

    /**
     * Naming a booking's detailers afresh (the source's
     * `update_booking_detailers`), checked across the booking's WHOLE span,
     * continuation days and its own released slots included. `$occupancy`
     * leaves this booking out.
     *
     * The heads reserved follow the names: the most of how many are named and
     * the package's minimum.
     *
     * @param  list<array{date: string, slot: int}>  $span
     * @param  list<string>  $detailerIds
     */
    public static function reassign(array $span, Occupancy $occupancy, int $pool, int $minDetailers, array $detailerIds): Admission
    {
        $count = count($detailerIds);
        if ($count < 1) {
            return Admission::refused(Refusal::NoDetailers);
        }
        if ($count < $minDetailers) {
            return Admission::refused(Refusal::BelowMinimum);
        }
        if ($span === []) {
            return Admission::refused(Refusal::NoSlots);
        }

        $remaining = $occupancy->minRemaining($span, $pool);
        if ($remaining !== null && $count > $remaining) {
            return Admission::refused(Refusal::OverCapacity, $remaining);
        }

        $conflicts = $occupancy->conflicts($span, $detailerIds);
        if ($conflicts !== []) {
            return Admission::refused(Refusal::DetailerConflict, $remaining, $conflicts);
        }

        return Admission::admitted(max($count, $minDetailers, 1), $detailerIds, $remaining);
    }
}
