<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

use DateTimeImmutable;

/**
 * What a status change does to a booking's two stamps (the source's
 * `update_booking_status`):
 *
 *  - `started_at` is set the FIRST time the job goes on-going and never again;
 *  - `completed_at` is set every time it is marked completed, and cleared
 *    when it is re-opened (on-going, confirmed, pending);
 *  - cancelling or marking a no-show leaves both as they were.
 */
final class BookingClock
{
    /**
     * @return array{started_at: DateTimeImmutable|null, completed_at: DateTimeImmutable|null}
     */
    public static function stamp(BookingStatus $to, ?DateTimeImmutable $startedAt, ?DateTimeImmutable $completedAt, DateTimeImmutable $now): array
    {
        return [
            'started_at' => $to === BookingStatus::OnGoing && $startedAt === null ? $now : $startedAt,
            'completed_at' => match (true) {
                $to === BookingStatus::Completed => $now,
                $to->reopens() => null,
                default => $completedAt,
            },
        ];
    }
}
