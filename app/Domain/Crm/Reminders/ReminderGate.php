<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

use App\Domain\Crm\QuietHours;
use App\Domain\Shared\Calendar;
use DateTimeImmutable;

/**
 * The checks between "worth sending" and "sent", in the order a refusal is
 * reported:
 *
 *  1. nowhere to send it (`no_address`);
 *  2. the purpose's consent is not on record, or was withdrawn (`no_consent`);
 *  3. the address opted out (`opted_out`);
 *  4. the same thing was sent within the cooldown (`recently_sent`).
 *
 * Quiet hours never refuse a message: they hold it until the window ends.
 * A purpose that needs no consent passes check 2.
 */
final class ReminderGate
{
    public static function decide(
        bool $hasAddress,
        bool $consentNeeded,
        bool $consented,
        bool $suppressed,
        ?DateTimeImmutable $lastSentAt,
        int $cooldownDays,
        QuietHours $quiet,
        DateTimeImmutable $now,
    ): GateDecision {
        if (! $hasAddress) {
            return GateDecision::skip(GateDecision::NO_ADDRESS);
        }
        if ($consentNeeded && ! $consented) {
            return GateDecision::skip(GateDecision::NO_CONSENT);
        }
        if ($suppressed) {
            return GateDecision::skip(GateDecision::SUPPRESSED);
        }
        if ($lastSentAt !== null && Calendar::differenceInCalendarDays($now, $lastSentAt) < $cooldownDays) {
            return GateDecision::skip(GateDecision::RECENTLY_SENT);
        }

        return GateDecision::send($quiet->nextAllowed($now));
    }
}
