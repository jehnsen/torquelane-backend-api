<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

/** The last finished job of one package on one vehicle, for a package that is due again after a number of days. */
final readonly class DetailingVisit
{
    public function __construct(
        public string $accountId,
        public string $branchId,
        public string $packageId,
        public string $packageName,
        /** The vehicle's id, or how the booking named it when it is on no record. */
        public string $vehicleKey,
        public string $vehicleLabel,
        public string $completedOn,
        public int $repeatIntervalDays,
        /** A booking for this customer at this branch is already on the sheet. */
        public bool $hasUpcomingBooking,
    ) {}
}
