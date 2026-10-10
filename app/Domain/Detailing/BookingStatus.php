<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * A booking's lifecycle. The source's values, with `on-going` spelled
 * `on_going`. As in the source any status may follow any other: the shop
 * corrects a mis-tap by tapping the right one. What each move does to the
 * clock and to the reserved slots is `BookingClock`'s and `SlotRelease`'s.
 */
enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case OnGoing = 'on_going';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::OnGoing => 'On-going',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    /** Whether the booking holds detailers: everything but a cancelled or no-show one. */
    public function holdsCapacity(): bool
    {
        return $this !== self::Cancelled && $this !== self::NoShow;
    }

    /** Moving back to one of these re-opens a finished job: its released slots come back. */
    public function reopens(): bool
    {
        return $this === self::OnGoing || $this === self::Confirmed || $this === self::Pending;
    }

    /** Shown on the schedule and the shop monitor: confirmed work, under way or done. */
    public function isOnTheFloor(): bool
    {
        return $this === self::Confirmed || $this === self::OnGoing || $this === self::Completed;
    }

    /** The source's spelling: `on-going`, with a hyphen. */
    public static function fromSource(string $value): ?self
    {
        return self::tryFrom($value === 'on-going' ? 'on_going' : $value);
    }

    public function sourceValue(): string
    {
        return $this === self::OnGoing ? 'on-going' : $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
