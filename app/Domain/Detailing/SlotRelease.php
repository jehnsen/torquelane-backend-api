<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * Early-finish release (the source's `update_booking_status`). A job marked
 * completed frees its detailers for the rest of what it had reserved: the
 * 30-minute bucket the completion time falls in, every later bucket that day,
 * and every later day. Only buckets that had fully elapsed stay held.
 *
 * Granularity is the bucket, not the minute: a job done at 9:45 keeps the 9:00
 * bucket and gives back 9:30 onward. A job that finishes after its last slot
 * releases nothing. Re-opening the job takes every slot back, exactly as they
 * were; nothing re-checks that the pool still has room.
 *
 * Released slots stay on record and count for nothing: no capacity sum and no
 * detailer conflict reads them.
 */
final class SlotRelease
{
    /**
     * The cells a completion frees.
     *
     * @param  list<array{date: string, slot: int}>  $span  the booking's slots
     * @param  string  $completedOn  the branch's business date at completion
     * @param  int  $completedMinutes  minutes since midnight, branch time
     * @return list<array{date: string, slot: int}>
     */
    public static function onCompletion(array $span, string $completedOn, int $completedMinutes): array
    {
        $floor = SlotGrid::bucketFloor($completedMinutes);

        return array_values(array_filter(
            $span,
            fn (array $cell): bool => $cell['date'] > $completedOn || ($cell['date'] === $completedOn && $cell['slot'] >= $floor),
        ));
    }
}
