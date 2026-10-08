<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** Port of ../web's `PmsItem`: one task evaluated against one vehicle. */
final readonly class PmsItem
{
    public function __construct(
        public ServiceTaskFacts $task,
        /** ok | due_soon | overdue */
        public string $status,
        /** Negative once the interval has been passed. */
        public float|int $kmRemaining,
        public int $daysRemaining,
        /** 0–1+ through the interval; above 1 means overdue. */
        public float|int $progress,
        public float|int $dueOdometer,
        /** Y-m-d */
        public string $dueDate,
        /** distance | time */
        public string $governedBy,
        public string $lastDoneOn,
        public float|int $lastDoneOdometer,
    ) {}
}
