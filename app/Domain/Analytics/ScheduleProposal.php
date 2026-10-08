<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Fleet\PmsItem;

/** One overdue item the auto-scheduler would book, and when. */
final readonly class ScheduleProposal
{
    public function __construct(
        public string $vehicleId,
        public PmsItem $item,
        /** Y-m-d, Manila */
        public string $scheduledFor,
        /** critical (a critical task) or high */
        public string $priority,
    ) {}
}
