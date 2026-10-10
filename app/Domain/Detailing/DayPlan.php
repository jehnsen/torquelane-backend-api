<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * Every (date, slot) a booking occupies. An empty plan means the start could
 * not host a single slot (the source's "overflow").
 */
final readonly class DayPlan
{
    /**
     * @param  list<PlannedDay>  $days
     */
    public function __construct(public array $days) {}

    public function isEmpty(): bool
    {
        return $this->days === [];
    }

    public function spansDays(): bool
    {
        return count($this->days) > 1;
    }

    public function firstDate(): ?string
    {
        return $this->days === [] ? null : $this->days[0]->date;
    }

    public function lastDate(): ?string
    {
        return $this->days === [] ? null : $this->days[count($this->days) - 1]->date;
    }

    public function slotCount(): int
    {
        return array_sum(array_map(fn (PlannedDay $day): int => count($day->slots), $this->days));
    }

    /**
     * The business dates touched, sorted: the order locks are taken in.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = array_values(array_unique(array_map(fn (PlannedDay $day): string => $day->date, $this->days)));
        sort($dates);

        return $dates;
    }

    /**
     * @return list<array{date: string, slot: int}>
     */
    public function cells(): array
    {
        $cells = [];
        foreach ($this->days as $day) {
            foreach ($day->slots as $slot) {
                $cells[] = ['date' => $day->date, 'slot' => $slot];
            }
        }

        return $cells;
    }

    /** When the work is expected to end on its last day: the end of its last slot. */
    public function endMinutes(): ?int
    {
        if ($this->days === []) {
            return null;
        }
        $slots = $this->days[count($this->days) - 1]->slots;

        return $slots === [] ? null : $slots[count($slots) - 1] + SlotGrid::SLOT_MINUTES;
    }
}
