<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * The engine's answer to "may this booking take these slots": either the
 * heads it reserves and the detailers it names, or the refusal and what to
 * say. `conflictIds` names the detailers already busy; `remaining` is how
 * many were free at the tightest slot.
 */
final readonly class Admission
{
    /**
     * @param  list<string>  $detailerIds
     * @param  list<string>  $conflictIds
     */
    private function __construct(
        public ?Refusal $refusal,
        public int $headcount,
        public array $detailerIds,
        public ?int $remaining,
        public array $conflictIds,
    ) {}

    /**
     * @param  list<string>  $detailerIds
     */
    public static function admitted(int $headcount, array $detailerIds, ?int $remaining): self
    {
        return new self(null, $headcount, $detailerIds, $remaining, []);
    }

    /**
     * @param  list<string>  $conflictIds
     */
    public static function refused(Refusal $refusal, ?int $remaining = null, array $conflictIds = []): self
    {
        return new self($refusal, 0, [], $remaining, $conflictIds);
    }

    public function isAdmitted(): bool
    {
        return $this->refusal === null;
    }

    /**
     * What the shop is told, in the source's words.
     *
     * @param  array<string, string>  $names  detailer id → name, for the conflict message
     * @param  bool  $reassigning  the message of `update_booking_detailers` rather than `add_booking`
     */
    public function message(array $names = [], int $minDetailers = 1, bool $reassigning = false): string
    {
        return match ($this->refusal) {
            null => '',
            Refusal::InvalidSlot => 'Invalid time slot.',
            Refusal::Blocked => 'This time is blocked.',
            Refusal::Full => 'This time slot just filled up. Please choose another.',
            Refusal::DetailerConflict => sprintf(
                'Already booked at this time: %s. %s',
                $this->conflictNames($names),
                $reassigning ? 'Choose a different detailer.' : 'Choose a different detailer or time slot.',
            ),
            Refusal::NoDetailers => 'At least one detailer must be assigned.',
            Refusal::BelowMinimum => sprintf('Service requires at least %d detailer(s).', $minDetailers),
            Refusal::NoSlots => 'Booking has no scheduled slots.',
            Refusal::OverCapacity => sprintf("Only %d detailer(s) available across this booking's hours.", $this->remaining ?? 0),
        };
    }

    /**
     * The busy detailers' names, each once, in alphabetical order.
     *
     * @param  array<string, string>  $names
     */
    private function conflictNames(array $names): string
    {
        $found = array_values(array_unique(array_map(fn (string $id): string => $names[$id] ?? $id, $this->conflictIds)));
        sort($found, SORT_STRING);

        return implode(', ', $found);
    }
}
