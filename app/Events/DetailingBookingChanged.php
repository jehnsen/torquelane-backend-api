<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Detailing\MonitorChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A detailing booking was made or changed (its status, detailers, bay,
 * extras, checklist, inspection, sign-off, bill). Dispatched only once the
 * transaction that changed it has committed, so a rolled-back change never
 * reaches a screen, and broadcast on its branch's private channel.
 *
 * It carries no customer data: a screen that hears it reads the monitor (or
 * the booking) again through the API, which decides what that screen may see.
 */
final class DetailingBookingChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public const string NAME = 'booking.changed';

    /**
     * @param  list<string>  $dates  every business date the booking touches (Y-m-d)
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $branchId,
        public readonly string $bookingId,
        public readonly string $change,
        public readonly array $dates,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(MonitorChannel::name($this->branchId))];
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{booking_id: string, change: string, dates: list<string>}
     */
    public function broadcastWith(): array
    {
        return ['booking_id' => $this->bookingId, 'change' => $this->change, 'dates' => $this->dates];
    }
}
