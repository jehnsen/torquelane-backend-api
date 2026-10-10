<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/** Why the engine would not take, or change, a booking. */
enum Refusal: string
{
    /** The start cannot host a single slot. */
    case InvalidSlot = 'invalid_slot';
    /** A slot of the plan is blocked by the shop. */
    case Blocked = 'blocked';
    /** Fewer detailers are free than the package needs. */
    case Full = 'full';
    /** A detailer named is already on another job in these hours. */
    case DetailerConflict = 'detailer_conflict';
    case NoDetailers = 'no_detailers';
    case BelowMinimum = 'below_minimum';
    case NoSlots = 'no_slots';
    /** More detailers named than are free across the booking's hours. */
    case OverCapacity = 'over_capacity';
}
