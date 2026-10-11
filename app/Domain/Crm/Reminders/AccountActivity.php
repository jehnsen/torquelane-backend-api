<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

/** When a customer was last served, and whether anything is booked ahead. */
final readonly class AccountActivity
{
    public function __construct(
        public string $accountId,
        /** `Y-m-d`; null for a customer never served (who cannot have lapsed). */
        public ?string $lastVisitOn,
        public bool $hasUpcomingWork,
    ) {}
}
