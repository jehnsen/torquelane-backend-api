<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

/** One maintenance item of a vehicle that the engine has in its warning or overdue band. */
final readonly class ServiceDue
{
    public function __construct(
        public string $accountId,
        public string $vehicleId,
        public string $plateNumber,
        public string $taskName,
        public bool $overdue,
        /** Signed days to the limit (negative once past). */
        public int $daysRemaining,
    ) {}
}
